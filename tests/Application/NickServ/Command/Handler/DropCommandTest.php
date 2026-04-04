<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\DropCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickForceService;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\DebugActionPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(DropCommand::class)]
final class DropCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsDrop(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('DROP', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('drop.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('drop.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyFive(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(75, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('drop.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsDropPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::DROP, $cmd->getRequiredPermission());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function getAuditDataReturnsNullBeforeExecute(): void
    {
        $cmd = $this->createCommand();

        self::assertNull($cmd->getAuditData($this->createStub(NickServContext::class)));
    }

    #[Test]
    public function getHelpParamsReturnsGuestPrefix(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(['%prefix%' => 'Guest-'], $cmd->getHelpParams());
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::never())->method('findByNick');

        $cmd = new DropCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(DebugActionPort::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $messages = [];
        $context = $this->createContext(null, ['TestNick'], $messages, nickRepository: $nickRepository);

        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithNonexistentNickRepliesNotRegistered(): void
    {
        $sender = $this->createSender();

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $context = $this->createContext($sender, ['UnknownNick'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(DebugActionPort::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.not_registered', $messages);
    }

    #[Test]
    public function executeWithPendingNickDropsSuccessfully(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');

        // Create a pending nick (not activated) with ID
        $nick = RegisteredNick::createPending('PendingNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, 42);

        // Verify it's pending (not activated)
        self::assertTrue($nick->isPending());

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('delete')->with($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (NickDropEvent $event): bool => 42 === $event->nickId
                && 'PendingNick' === $event->nickname
                && 'manual' === $event->reason));

        $debug = $this->createMock(DebugActionPort::class);
        $debug->expects(self::once())->method('log')->with(
            'OperUser',
            'DROP',
            'PendingNick',
            null,
            null,
            'manual drop',
        );

        $context = $this->createContext(
            $sender,
            ['PendingNick'],
            $messages,
            nickRepository: $nickRepository,
            ircopRepository: $ircopRepository,
            userLookup: $userLookup,
            forceService: $forceService,
            eventDispatcher: $eventDispatcher,
            debug: $debug,
        );

        $cmd = new DropCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('PendingNick', $auditData->target);
        self::assertSame('manual drop', $auditData->reason);
        self::assertSame(['was_online' => false], $auditData->extra);
    }

    #[Test]
    public function executeWithSuspendedNickRepliesSuspended(): void
    {
        $sender = $this->createSender();
        $nick = $this->createActivatedNick('TestNick');
        $nick->suspend('Previous reason');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext($sender, ['TestNick'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(DebugActionPort::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.suspended', $messages);
    }

    #[Test]
    public function executeWithForbiddenNickRepliesForbidden(): void
    {
        $sender = $this->createSender();
        $nick = RegisteredNick::createForbidden('BadNick', 'Forbidden for spam');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext($sender, ['BadNick'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(DebugActionPort::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.forbidden', $messages);
    }

    #[Test]
    public function executeWithRootNickRepliesCannotDropRoot(): void
    {
        $sender = $this->createSender();
        $nick = $this->createActivatedNick('RootUser');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $rootRegistry = new RootUserRegistry('RootUser');

        $context = $this->createContext($sender, ['RootUser'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $rootRegistry,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(DebugActionPort::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.cannot_drop_root', $messages);
    }

    #[Test]
    public function executeWithIrcopNickRepliesCannotDropOper(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('OperUser', 1);

        $role = new OperRole('Admin', 'desc');
        $ircop = OperIrcop::create(1, $role);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn($ircop);

        $context = $this->createContext($sender, ['OperUser'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(DebugActionPort::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.cannot_drop_oper', $messages);
    }

    #[Test]
    public function executeWithSelfDropRepliesCannotDropSelf(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $nick = $this->createNickWithId('OperUser', 1);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $context = $this->createContext($sender, ['OperUser'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(DebugActionPort::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.cannot_drop_self', $messages);
    }

    #[Test]
    public function executeWithOnlineUserForcesRenameThenDrops(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $nick = $this->createNickWithId('TargetNick', 42);

        $onlineUser = new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'o', '');

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('delete')->with($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($onlineUser);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::once())->method('forceGuestNick')->with('UID2', null, 'ircop-drop');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (NickDropEvent $event): bool => 42 === $event->nickId
                && 'TargetNick' === $event->nickname
                && 'manual' === $event->reason));

        $debug = $this->createMock(DebugActionPort::class);
        $debug->expects(self::once())->method('log')->with(
            'OperUser',
            'DROP',
            'TargetNick',
            null,
            null,
            'manual drop',
        );

        $context = $this->createContext(
            $sender,
            ['TargetNick'],
            $messages,
            nickRepository: $nickRepository,
            ircopRepository: $ircopRepository,
            userLookup: $userLookup,
            forceService: $forceService,
            eventDispatcher: $eventDispatcher,
            debug: $debug,
        );

        $cmd = new DropCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TargetNick', $auditData->target);
        self::assertSame('manual drop', $auditData->reason);
        self::assertSame(['was_online' => true], $auditData->extra);
    }

    #[Test]
    public function executeWithOfflineUserDropsSuccessfully(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $nick = $this->createNickWithId('TargetNick', 42);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('delete')->with($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $forceService = $this->createMock(NickForceService::class);
        $forceService->expects(self::never())->method('forceGuestNick');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $debug = $this->createMock(DebugActionPort::class);
        $debug->expects(self::once())->method('log');

        $context = $this->createContext(
            $sender,
            ['TargetNick'],
            $messages,
            nickRepository: $nickRepository,
            ircopRepository: $ircopRepository,
            userLookup: $userLookup,
            forceService: $forceService,
            eventDispatcher: $eventDispatcher,
            debug: $debug,
        );

        $cmd = new DropCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $userLookup,
            $forceService,
            $eventDispatcher,
            $debug,
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );

        $cmd->execute($context);

        self::assertContains('drop.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame(['was_online' => false], $auditData->extra);
    }

    private function createCommand(): DropCommand
    {
        return new DropCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickForceService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(DebugActionPort::class),
            $this->createStub(LoggerInterface::class),
            'Guest-',
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
    }

    private function createActivatedNick(string $nickname): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        return $nick;
    }

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        array &$messages,
        ?RegisteredNickRepositoryInterface $nickRepository = null,
        ?OperIrcopRepositoryInterface $ircopRepository = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?NickForceService $forceService = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?DebugActionPort $debug = null,
    ): NickServContext {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $type, string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            null,
            'DROP',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');

        return new ServiceNicknameRegistry([$provider]);
    }
}
