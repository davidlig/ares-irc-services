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
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickSuspensionService;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickSuspendedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
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
    public function executeWithEmptyIpStoresAsteriskInEvent(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TestNick', $nick));

        $suspensionService = $this->createMock(NickSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', '', false, true, 'SID1', 'h', 'o', '');

        $context = $this->createContext($sender, ['TestNick', '7d', 'Test reason'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $suspensionService,
            $eventDispatcher,
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(NickSuspendedEvent::class, $dispatchedEvents[0]);
        self::assertSame('*', $dispatchedEvents[0]->performedByIp);
    }

    #[Test]
    public function executeWithInvalidBase64IpStoresOriginalInEvent(): void
    {
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TestNick', $nick));

        $suspensionService = $this->createMock(NickSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'invalid!base64', false, true, 'SID1', 'h', 'o', '');

        $context = $this->createContext($sender, ['TestNick', '7d', 'Test reason'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $suspensionService,
            $eventDispatcher,
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(NickSuspendedEvent::class, $dispatchedEvents[0]);
        self::assertSame('invalid!base64', $dispatchedEvents[0]->performedByIp);
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();

        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function executeWithEmptyReasonRepliesSyntaxError(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $context = $this->createContext($sender, ['TestNick', '7d'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
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

        $context = $this->createContext($sender, ['UnknownNick', '7d', 'Test reason'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
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

        $context = $this->createContext($sender, ['BadNick', '7d', 'Test reason'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
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

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::root('RootUser'));

        $context = $this->createContext($sender, ['RootUser', '7d', 'Testing'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.cannot_suspend_root', $messages);
    }

    #[Test]
    public function executeWithIrcopNickRepliesCannotSuspendOper(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('OperUser', 1);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::ircop('OperUser'));

        $context = $this->createContext($sender, ['OperUser', '7d', 'Testing'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.cannot_suspend_oper', $messages);
    }

    #[Test]
    public function executeWithServiceNickRepliesCannotSuspendService(): void
    {
        $sender = $this->createSender();
        $nick = $this->createActivatedNick('NickServ');

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::service('NickServ'));

        $context = $this->createContext($sender, ['NickServ', '7d', 'Testing'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.cannot_suspend_service', $messages);
    }

    #[Test]
    public function executeWithInvalidDurationRepliesInvalidDuration(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TestNick', $nick));

        $context = $this->createContext($sender, ['TestNick', 'invalid', 'Reason'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.invalid_duration', $messages);
    }

    #[Test]
    public function executeWithPermanentDurationSuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save')->with($nick);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TestNick', $nick));

        $suspensionService = $this->createMock(NickSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension')->with($nick);

        $context = $this->createContext($sender, ['TestNick', '0', 'Permanent'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $suspensionService,
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($nick->isSuspended());

        $auditData = $cmd->getAuditData($context);
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TestNick', $auditData->target);
        self::assertSame('Permanent', $auditData->reason);
    }

    #[Test]
    public function executeWithDurationSuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TestNick', $nick));

        $suspensionService = $this->createMock(NickSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $context = $this->createContext($sender, ['TestNick', '7d', 'Testing'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $suspensionService,
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($nick->isSuspended());
    }

    #[Test]
    public function executeWithMinuteDurationSuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TestNick', $nick));

        $suspensionService = $this->createMock(NickSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $context = $this->createContext($sender, ['TestNick', '30m', 'Testing'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $suspensionService,
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($nick->isSuspended());
    }

    #[Test]
    public function executeWithHourDurationSuspendsSuccessfully(): void
    {
        $sender = $this->createSender();
        $nick = $this->createNickWithId('TestNick', 1);

        $messages = [];
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findByNick')->willReturn($nick);
        $nickRepository->expects(self::once())->method('save');

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TestNick', $nick));

        $suspensionService = $this->createMock(NickSuspensionService::class);
        $suspensionService->expects(self::once())->method('enforceSuspension');

        $context = $this->createContext($sender, ['TestNick', '2h', 'Testing'], $messages, nickRepository: $nickRepository);

        $cmd = new SuspendCommand(
            $nickRepository,
            $validator,
            $suspensionService,
            $this->createStub(EventDispatcherInterface::class),
        );

        $cmd->execute($context);

        self::assertContains('suspend.success', $messages);
        self::assertTrue($nick->isSuspended());
    }

    private function createCommand(): SuspendCommand
    {
        return new SuspendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickTargetValidator::class),
            $this->createStub(NickSuspensionService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(EventDispatcherInterface::class),
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
