<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Application\Command\IrcopAuditData;
use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\Command\ForbidCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Application\Service\NickProtectabilityResult;
use App\NickServ\Application\Service\NickTargetValidator;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

#[CoversClass(ForbidCommand::class)]
final class ForbidCommandTest extends TestCase
{
    #[Test]
    public function getNameReturnsForbid(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('FORBID', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('forbid.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('forbid.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSeventyTwo(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(69, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsCorrectKey(): void
    {
        $cmd = $this->createCommand();

        self::assertSame('forbid.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsForbidPermission(): void
    {
        $cmd = $this->createCommand();

        self::assertSame(NickServPermission::FORBID, $cmd->getRequiredPermission());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getSubCommandHelp());
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

        $cmd = new ForbidCommand(
            $nickRepository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $messages = [];
        $context = $this->createContext(null, ['TestNick', 'Test reason'], $messages);

        $outcome = $cmd->execute($context);

        self::assertEmpty($messages);
    }

    #[Test]
    public function executeWithoutReasonRepliesReasonRequired(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $context = $this->createContext($sender, ['TestNick'], $messages);

        $cmd = new ForbidCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickTargetValidator::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('forbid.reason_required', $messages);
    }

    #[Test]
    public function executeWithRootNickRepliesCannotForbidRoot(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::root('RootUser'));

        $context = $this->createContext($sender, ['RootUser', 'Test reason'], $messages);

        $cmd = new ForbidCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $validator,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('forbid.cannot_forbid_root', $messages);
    }

    #[Test]
    public function executeWithIrcopNickRepliesCannotForbidOper(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::ircop('OperUser'));

        $context = $this->createContext($sender, ['OperUser', 'Test reason'], $messages);

        $cmd = new ForbidCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $validator,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('forbid.cannot_forbid_oper', $messages);
    }

    #[Test]
    public function executeWithServiceNickRepliesCannotForbidService(): void
    {
        $sender = $this->createSender();
        $messages = [];

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::service('NickServ'));

        $context = $this->createContext($sender, ['NickServ', 'Test reason'], $messages);

        $cmd = new ForbidCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $validator,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('forbid.cannot_forbid_service', $messages);
    }

    #[Test]
    public function executeWithExistingForbiddenUpdatesReason(): void
    {
        $sender = $this->createSender();
        $nick = RegisteredNick::createForbidden('BadNick', 'Old reason');
        $messages = [];

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('BadNick', $nick));

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('updateReason')->with($nick, 'New reason');

        $context = $this->createContext($sender, ['BadNick', 'New reason'], $messages, nickRepository: $nickRepository);

        $cmd = new ForbidCommand(
            $nickRepository,
            $validator,
            $forbiddenService,
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('forbid.updated', $messages);
    }

    #[Test]
    public function executeWithUnregisteredNickCreatesForbidden(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $messages = [];

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn(null);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('BadNick', null));

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('forbid')->with('BadNick', 'Test reason', 'OperUser');

        $context = $this->createContext($sender, ['BadNick', 'Test reason'], $messages, nickRepository: $nickRepository);

        $cmd = new ForbidCommand(
            $nickRepository,
            $validator,
            $forbiddenService,
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );

        $outcome = $cmd->execute($context);

        self::assertContains('forbid.success', $messages);

        $auditData = $outcome->auditData;
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('BadNick', $auditData->target);
        self::assertSame('Test reason', $auditData->reason);
    }

    #[Test]
    public function executeWithRegisteredNickDropsThenCreatesForbidden(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $nick = $this->createActivatedNick('BadUser');
        $messages = [];

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('BadUser', $nick));

        $dropService = $this->createMock(NickDropService::class);
        $dropService->expects(self::once())->method('dropNick')->with($nick, 'forbid', 'OperUser');

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('forbid')->with('BadUser', 'Test reason', 'OperUser');

        $context = $this->createContext($sender, ['BadUser', 'Test reason'], $messages, nickRepository: $nickRepository);

        $cmd = new ForbidCommand(
            $nickRepository,
            $validator,
            $forbiddenService,
            $dropService,
            $this->createStub(LoggerInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('forbid.success', $messages);
    }

    private function createCommand(): ForbidCommand
    {
        return new ForbidCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickTargetValidator::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickDropService::class),
            $this->createStub(LoggerInterface::class),
        );
    }

    private function createSender(): SenderView
    {
        return new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o');
    }

    private function createActivatedNick(string $nickname): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($nick, 1);

        return $nick;
    }

    /**
     * @param string[] $args
     * @param string[] $messages
     */
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

        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            null,
            'FORBID',
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
