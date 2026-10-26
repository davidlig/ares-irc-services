<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationContextInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\ChanServService;
use App\ChanServ\Adapter\In\Irc\ChanServUserPresentationPreferences;
use App\ChanServ\Adapter\In\Irc\Command\CregCommand;
use App\ChanServ\Adapter\In\Irc\Command\HelpCommand;
use App\ChanServ\Adapter\In\Irc\Command\RegmodeCommand;
use App\ChanServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\ListPendingRegistrationsHandler;
use App\ChanServ\Application\UseCase\RegistrationMode\RegistrationModeHandlerInterface;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandlerInterface;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\Shared\Application\Port\EventBusInterface;
use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RegmodeCommand::class)]
final class RegmodeCommandTest extends TestCase
{
    #[Test]
    public function isPermissionGatedByTheRegistrationModePermission(): void
    {
        $command = new RegmodeCommand($this->createStub(RegistrationModeHandlerInterface::class));

        self::assertSame(ChanServPermission::REGMODE, $command->getRequiredPermission());
        self::assertSame('REGMODE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame('regmode.syntax', $command->getArgumentDefinition()->syntaxKey);
        self::assertSame('regmode.help', $command->getHelpKey());
        self::assertSame('regmode.example', $command->getExampleKey());
        self::assertSame(3, $command->getOrder());
        self::assertSame('regmode.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertTrue($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function statusReadsPersistedModeWithoutChangingIt(): void
    {
        $handler = $this->createMock(RegistrationModeHandlerInterface::class);
        $handler->expects(self::once())->method('current')->willReturn(RegistrationMode::Approval);
        $handler->expects(self::never())->method('change');
        [$command, $context, $messages] = $this->commandContext($handler, ['STATUS']);

        $outcome = $command->execute($context);

        self::assertSame(['regmode.status'], iterator_to_array($messages));
        self::assertSame(['mode' => 'APPROVAL'], $outcome->auditData?->extra);
    }

    #[Test]
    public function directAndApprovalPersistThroughHandlerAndReportAuditData(): void
    {
        foreach ([['DIRECT', RegistrationMode::Direct], ['APPROVAL', RegistrationMode::Approval]] as [$argument, $mode]) {
            $handler = $this->createMock(RegistrationModeHandlerInterface::class);
            $handler->expects(self::once())->method('change')->with($mode, 'Operator')->willReturn(RegistrationMode::Direct);
            [$command, $context, $messages] = $this->commandContext($handler, [$argument]);

            $outcome = $command->execute($context);

            self::assertSame(['regmode.changed'], iterator_to_array($messages));
            self::assertSame(['previous' => 'DIRECT', 'mode' => $mode->value], $outcome->auditData?->extra);
        }
    }

    #[Test]
    public function invalidModeAndMissingSenderHaveNoPersistenceEffects(): void
    {
        $handler = $this->createMock(RegistrationModeHandlerInterface::class);
        $handler->expects(self::never())->method('change');
        $handler->expects(self::never())->method('current');
        [$command, $context, $messages] = $this->commandContext($handler, ['OTHER']);
        self::assertFalse($command->execute($context)->success);
        self::assertSame(['error.syntax'], iterator_to_array($messages));

        [$command, $context, $messages] = $this->commandContext($handler, ['DIRECT'], true);
        self::assertFalse($command->execute($context)->success);
        self::assertSame([], iterator_to_array($messages));
    }

    #[Test]
    public function serviceRoutingDeniesUnpermittedModeAndReviewCommandsBeforeUseCases(): void
    {
        $modeHandler = $this->createMock(RegistrationModeHandlerInterface::class);
        $modeHandler->expects(self::exactly(2))->method('current')->willReturn(RegistrationMode::Direct);
        $modeHandler->expects(self::never())->method('change');
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::exactly(6))->method('findAccountByNick')->willReturnCallback(
            static fn (string $nick): ChanAccountView => new ChanAccountView(9, $nick, 'en'),
        );
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::exactly(2))->method('iterateAll')->willReturn([]);
        $channels->expects(self::never())->method('findByChannelName');
        $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
        $review->expects(self::never())->method('handle');
        $pending = new ListPendingRegistrationsHandler($channels, $accounts);
        $registry = new ChanServCommandRegistry([
            new RegmodeCommand($modeHandler),
            new CregCommand($pending, $review),
        ]);
        $authorization = $this->createMock(ChanAuthorizationCheckerInterface::class);
        $authorization->expects(self::exactly(10))->method('isGranted')->willReturnOnConsecutiveCalls(false, false, true, false, true, false, true, false, true, false);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message, string $type) use (&$messages): void { $messages[] = $message; });
        $modeSupport = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupport->method('getSupport')->willReturn(new NullChannelModeSupport());
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $service = new ChanServService(
            $registry,
            $channels,
            $accounts,
            $this->createStub(ChanServUserPresentationPreferences::class),
            $notifier,
            $translator,
            $this->createStub(ChannelLookupPort::class),
            $modeSupport,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(ServiceNicknameRegistry::class),
            $this->createStub(ChanAuthorizationContextInterface::class),
            $authorization,
            $this->createStub(EventBusInterface::class),
            $this->createStub(CommandAuditRecorder::class),
        );
        $root = new SenderView('UID1', 'Root', 'i', 'h', 'c', 'ip', true, false);
        $service->dispatch('REGMODE STATUS', $root);
        $service->dispatch('CREG PENDING', $root);
        $service->dispatch('REGMODE STATUS', $root);
        $service->dispatch('CREG PENDING', $root);
        $roleOper = new SenderView('UID2', 'RoleOper', 'i', 'h', 'c', 'ip', true, true);
        $service->dispatch('REGMODE STATUS', $roleOper);
        $service->dispatch('CREG PENDING', $roleOper);

        self::assertSame([
            'error.permission_denied', 'error.permission_denied',
            'regmode.status', 'creg.pending_header',
            'regmode.status', 'creg.pending_header',
        ], $messages);
    }

    #[Test]
    public function directHelpForModeCommandsIsVisibleOnlyWhenThePermissionIsGranted(): void
    {
        $mode = new RegmodeCommand($this->createStub(RegistrationModeHandlerInterface::class));
        $review = new CregCommand(
            new ListPendingRegistrationsHandler($this->createStub(RegisteredChannelRepositoryInterface::class), $this->createStub(ChanUserAccountPort::class)),
            $this->createStub(ReviewRegistrationHandlerInterface::class),
        );
        $registry = new ChanServCommandRegistry([$mode, $review]);
        $access = $this->createStub(ChanServOperatorAccess::class);
        $access->method('hasAnyPermission')->willReturn(true);
        $access->method('hasPermission')->willReturnCallback(static fn (string $nick, int $accountId, bool $identified, bool $oper, string $permission): bool => in_array($permission, [ChanServPermission::REGMODE, ChanServPermission::REGISTRATION_REVIEW], true));
        $help = new HelpCommand(new UnifiedHelpFormatter(), $access);
        [$unused, $authorizedContext, $authorizedMessages] = $this->commandContext($this->createStub(RegistrationModeHandlerInterface::class), ['REGMODE'], false, $registry);
        $help->execute($authorizedContext);
        self::assertStringContainsString('regmode.help', iterator_to_array($authorizedMessages)[0]);
        [$unused, $deniedContext, $deniedMessages] = $this->commandContext($this->createStub(RegistrationModeHandlerInterface::class), ['REGMODE'], false, $registry);
        $deniedAccess = $this->createStub(ChanServOperatorAccess::class);
        $deniedAccess->method('hasAnyPermission')->willReturn(false);
        $deniedAccess->method('hasPermission')->willReturn(false);
        new HelpCommand(new UnifiedHelpFormatter(), $deniedAccess)->execute($deniedContext);
        self::assertSame(['unknown_command'], iterator_to_array($deniedMessages));
    }

    /** @param list<string> $arguments
     * @return array{RegmodeCommand, ChanServContext, ArrayObject<int, string>}
     */
    private function commandContext(RegistrationModeHandlerInterface $handler, array $arguments, bool $withoutSender = false, ?ChanServCommandRegistry $registry = null): array
    {
        /** @var ArrayObject<int, string> $messages */
        $messages = new ArrayObject();
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void { $messages[] = $message; });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = new ChanServContext(
            $withoutSender ? null : new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip', isIdentified: true),
            new ChanAccountView(9, 'Operator', 'en'),
            'REGMODE',
            $arguments,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry ?? new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(ServiceNicknameRegistry::class),
        );

        return [new RegmodeCommand($handler), $context, $messages];
    }
}
