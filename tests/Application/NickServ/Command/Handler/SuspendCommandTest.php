<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\SuspendCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickSuspensionService;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SuspendCommand::class)]
final class SuspendCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsSuspend(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('SUSPEND', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsThree(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(3, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('suspend.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('suspend.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventy(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(70, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('suspend.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsSuspendPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::SUSPEND, $cmd->getRequiredPermission());
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
    public function executeWithEmptyReasonRepliesSyntaxError(): void
    {
        $sender = $this->createSender();
        $nick = $this->createActivatedNick('TestNick');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext($sender, ['TestNick', '7d', '   '], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NickSuspensionService::class),
        );

        $cmd->execute($context);

        self::assertContains('error.syntax', $messages);
    }

    #[Test]
    public function executeWithNonexistentNickRepliesNotRegistered(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $context = $this->createContext($sender, ['UnknownNick', '7d', 'Spamming'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NickSuspensionService::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.not_registered', $messages);
    }

    #[Test]
    public function executeWithForbiddenNickRepliesForbidden(): void
    {
        $sender = $this->createSender();
        $nick = RegisteredNick::createForbidden('BadNick', 'Forbidden for spam');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext($sender, ['BadNick', '7d', 'More spam'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NickSuspensionService::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.forbidden', $messages);
    }

    #[Test]
    public function executeWithAlreadySuspendedNickRepliesAlreadySuspended(): void
    {
        $sender = $this->createSender();
        $nick = $this->createActivatedNick('TestNick');
        $nick->suspend('Previous reason');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext($sender, ['TestNick', '7d', 'Another reason'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NickSuspensionService::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.already_suspended', $messages);
    }

    #[Test]
    public function executeWithRootNickRepliesCannotSuspendRoot(): void
    {
        $sender = $this->createSender();
        $nick = $this->createActivatedNick('RootUser');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $rootRegistry = new RootUserRegistry('RootUser');

        $context = $this->createContext($sender, ['RootUser', '7d', 'Testing'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $rootRegistry,
            $this->createStub(NickSuspensionService::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.cannot_suspend_root', $messages);
    }

    #[Test]
    public function executeWithIrcopNickRepliesCannotSuspendOper(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('OperUser', 1);
        $nick->activate();

        $role = new OperRole('Admin', 'desc');
        $ircop = OperIrcop::create(1, $role);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn($ircop);

        $context = $this->createContext($sender, ['OperUser', '7d', 'Testing'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $this->createStub(NickSuspensionService::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.cannot_suspend_oper', $messages);
    }

    #[Test]
    public function executeWithInvalidDurationRepliesInvalidDuration(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->activate();

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $context = $this->createContext($sender, ['TestNick', 'invalid', 'Reason'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $this->createStub(NickSuspensionService::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.invalid_duration', $messages);
    }

    #[Test]
    public function executeWithPermanentDurationSuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->activate();

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $suspensionService = $this->createStub(NickSuspensionService::class);

        $context = $this->createContext($sender, ['TestNick', '0', 'Permanent suspension'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $suspensionService,
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($nick->isSuspended());
        self::assertSame('Permanent suspension', $nick->getReason());
        self::assertNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function executeWithDurationSuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->activate();

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $suspensionService = $this->createStub(NickSuspensionService::class);

        $context = $this->createContext($sender, ['TestNick', '7d', 'Spamming'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $suspensionService,
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($nick->isSuspended());
        self::assertSame('Spamming', $nick->getReason());
        self::assertNotNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function executeWithHoursDurationParsesCorrectly(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->activate();

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $suspensionService = $this->createStub(NickSuspensionService::class);

        $context = $this->createContext($sender, ['TestNick', '12h', 'Testing'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $suspensionService,
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($nick->isSuspended());
        self::assertNotNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function executeWithMinutesDurationParsesCorrectly(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->activate();

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $suspensionService = $this->createStub(NickSuspensionService::class);

        $context = $this->createContext($sender, ['TestNick', '30m', 'Testing'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $suspensionService,
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($nick->isSuspended());
        self::assertNotNull($nick->getSuspendedUntil());
    }

    #[Test]
    public function getAuditDataReturnsDataAfterSuccessfulExecute(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);
        $nick->activate();

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturn(null);

        $suspensionService = $this->createStub(NickSuspensionService::class);

        $context = $this->createContext($sender, ['TestNick', '7d', 'Spamming'], $messages, nickRepository: $nickRepository, ircopRepository: $ircopRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $ircopRepository,
            new RootUserRegistry(''),
            $suspensionService,
        );

        $cmd->execute($context);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TestNick', $auditData->target);
        self::assertSame('Spamming', $auditData->reason);
        self::assertSame(['duration' => '7d'], $auditData->extra);
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    private function createCommand(): SuspendCommand
    {
        return new SuspendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            new RootUserRegistry(''),
            $this->createStub(NickSuspensionService::class),
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
            'SUSPEND',
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
