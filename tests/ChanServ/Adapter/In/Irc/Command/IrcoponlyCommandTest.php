<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\IrcoponlyCommand;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleAction;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleOutcome;
use App\ChanServ\Application\UseCase\ManageLifecycle\ChannelLifecycleResult;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycle;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandlerInterface;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IrcoponlyCommand::class)]
#[UsesClass(ChanServContext::class)]
#[UsesClass(ChanServCommandRegistry::class)]
#[UsesClass(ServiceNicknameRegistry::class)]
#[UsesClass(NullChannelModeSupport::class)]
#[UsesClass(ManageChannelLifecycle::class)]
#[UsesClass(ChannelLifecycleResult::class)]
final class IrcoponlyCommandTest extends TestCase
{
    #[Test]
    public function metadataRequiresIrcopPermission(): void
    {
        $command = new IrcoponlyCommand($this->createStub(ManageChannelLifecycleHandlerInterface::class));
        self::assertSame('IRCOPONLY', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('ircoponly.syntax', $command->getSyntaxKey());
        self::assertSame('ircoponly.help', $command->getHelpKey());
        self::assertSame('ircoponly.short', $command->getShortDescKey());
        self::assertSame(77, $command->getOrder());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertSame([], $command->getHelpParams());
        self::assertTrue($command->isOperOnly());
        self::assertSame(ChanServPermission::IRCOPONLY, $command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertFalse($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function rejectedInputDoesNotCallUseCase(): void
    {
        $handler = $this->createMock(ManageChannelLifecycleHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new IrcoponlyCommand($handler);
        $messages = [];
        self::assertFalse($command->execute($this->context(null, ['#ops', 'ON'], $messages))->success);
        self::assertFalse($command->execute($this->context($this->sender(), ['bad', 'ON'], $messages))->success);
        self::assertFalse($command->execute($this->context($this->sender(), ['#ops', 'INVALID'], $messages))->success);
        self::assertSame(['error.invalid_channel', 'error.syntax'], $messages);
    }

    #[Test]
    public function onAndOffSendLifecycleActionsAndAudit(): void
    {
        $handler = $this->createMock(ManageChannelLifecycleHandlerInterface::class);
        $handler->expects(self::exactly(2))->method('handle')->willReturnCallback(static function (ManageChannelLifecycle $request): ChannelLifecycleResult {
            self::assertSame('#ops', $request->channelName);
            self::assertSame('Oper', $request->actorNickname);

            return new ChannelLifecycleResult(ChannelLifecycleAction::EnableIrcopOnly === $request->action ? ChannelLifecycleOutcome::IrcopOnlyEnabled : ChannelLifecycleOutcome::IrcopOnlyDisabled);
        });
        $command = new IrcoponlyCommand($handler);
        $messages = [];
        $on = $command->execute($this->context($this->sender(), ['#ops', 'on'], $messages));
        $off = $command->execute($this->context($this->sender(), ['#ops', 'OFF'], $messages));
        self::assertSame(['ircoponly.on', 'ircoponly.off'], $messages);
        self::assertTrue($on->success);
        self::assertTrue($off->success);
        self::assertInstanceOf(IrcopAuditData::class, $on->auditData);
        self::assertSame(['option' => 'ON'], $on->auditData->extra);
        self::assertInstanceOf(IrcopAuditData::class, $off->auditData);
        self::assertSame(['option' => 'OFF'], $off->auditData->extra);
    }

    #[Test]
    public function notRegisteredAndForbiddenOutcomesAreReported(): void
    {
        $handler = $this->createStub(ManageChannelLifecycleHandlerInterface::class);
        $handler->method('handle')->willReturnOnConsecutiveCalls(new ChannelLifecycleResult(ChannelLifecycleOutcome::NotRegistered), new ChannelLifecycleResult(ChannelLifecycleOutcome::ChannelForbidden));
        $command = new IrcoponlyCommand($handler);
        $messages = [];
        self::assertFalse($command->execute($this->context($this->sender(), ['#ops', 'ON'], $messages))->success);
        self::assertFalse($command->execute($this->context($this->sender(), ['#ops', 'OFF'], $messages))->success);
        self::assertSame(['error.channel_not_registered', 'forbid.channel_forbidden'], $messages);
    }

    private function sender(): SenderView
    {
        return new SenderView('U1', 'Oper', 'ident', 'host', 'cloak', '*', true, true, 'SID', 'host', 'o');
    }

    /**
     * @param list<string> $args
     * @param list<string> $messages
     */
    private function context(?SenderView $sender, array $args, array &$messages): ChanServContext
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void { $messages[] = $message; });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return new ChanServContext($sender, null, 'IRCOPONLY', $args, $notifier, $translator, 'en', 'UTC', 'NOTICE', new ChanServCommandRegistry([]), $this->createStub(ChannelLookupPort::class), new NullChannelModeSupport(), $this->createStub(NetworkUserLookupPort::class), new ServiceNicknameRegistry([]));
    }
}
