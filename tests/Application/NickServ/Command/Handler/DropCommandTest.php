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
use App\Application\NickServ\Service\NickDropService;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
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

        self::assertSame(71, $cmd->getOrder());
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
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function executeWithNullSenderReturnsEarly(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::never())->method('findByNick');

        $cmd = new DropCommand(
            $nickRepository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $messages = [];
        $context = $this->createContext(null, ['TestNick'], $messages, nickRepository: $nickRepository);

        $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithSelfDropRepliesCannotDropSelf(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $nick = $this->createNickWithId('OperUser', 1);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext($sender, ['OperUser'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('drop.cannot_drop_self', $messages);
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
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('drop.not_registered', $messages);
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
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
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
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
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

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::root('RootUser'));

        $context = $this->createContext($sender, ['RootUser'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $validator,
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('drop.cannot_drop_root', $messages);
    }

    #[Test]
    public function executeWithIrcopNickRepliesCannotDropOper(): void
    {
        $sender = new SenderView('UID1', 'AdminUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $nick = $this->createNickWithId('OperUser', 1);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::ircop('OperUser'));

        $context = $this->createContext($sender, ['OperUser'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $validator,
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('drop.cannot_drop_oper', $messages);
    }

    #[Test]
    public function executeWithAllowedNickDropsSuccessfully(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o', '');
        $nick = $this->createNickWithId('TargetNick', 42);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TargetNick', $nick));

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('dropNick')->with($nick, 'manual', 'OperUser');

        $context = $this->createContext($sender, ['TargetNick'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $validator,
            $dropService,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('drop.success', $messages);

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TargetNick', $auditData->target);
    }

    #[Test]
    public function executeWithServiceNickRepliesCannotDropService(): void
    {
        $sender = $this->createSender();
        $nick = $this->createActivatedNick('NickServ');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::service('NickServ'));

        $context = $this->createContext($sender, ['NickServ'], $messages, nickRepository: $nickRepository);

        $cmd = new DropCommand(
            $nickRepository,
            $validator,
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('drop.cannot_drop_service', $messages);
    }

    private function createCommand(): DropCommand
    {
        return new DropCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
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
