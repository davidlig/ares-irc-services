<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\ClearaccessCommand;
use App\ChanServ\Adapter\In\Irc\Command\ClearusersCommand;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccessHandlerInterface;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccessOutcome;
use App\ChanServ\Application\UseCase\ClearAccess\ClearChannelAccessResult;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsersHandlerInterface;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsersOutcome;
use App\ChanServ\Application\UseCase\ClearUsers\ClearChannelUsersResult;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ClearaccessCommand::class)]
#[CoversClass(ClearusersCommand::class)]
final class ClearChannelCommandsTest extends TestCase
{
    #[Test]
    public function clearAccessExposesMetadata(): void
    {
        $command = new ClearaccessCommand($this->createStub(ClearChannelAccessHandlerInterface::class));

        self::assertSame('CLEARACCESS', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('clearaccess.syntax', $command->getSyntaxKey());
        self::assertSame('clearaccess.help', $command->getHelpKey());
        self::assertSame(74, $command->getOrder());
        self::assertSame('clearaccess.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('chanserv.clearaccess', $command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertFalse($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function clearUsersExposesMetadata(): void
    {
        $command = new ClearusersCommand($this->createStub(ClearChannelUsersHandlerInterface::class));

        self::assertSame('CLEARUSERS', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('clearusers.syntax', $command->getSyntaxKey());
        self::assertSame('clearusers.help', $command->getHelpKey());
        self::assertSame(73, $command->getOrder());
        self::assertSame('clearusers.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('chanserv.clearusers', $command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertFalse($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function bothRejectMissingSenderAndInvalidChannel(): void
    {
        $access = $this->createMock(ClearChannelAccessHandlerInterface::class);
        $access->expects(self::never())->method('handle');
        self::assertFalse(new ClearaccessCommand($access)->execute($this->context(null, ['#channel']))->success);

        $messages = [];
        self::assertFalse(new ClearaccessCommand($access)->execute($this->context($this->sender(), ['invalid'], $messages))->success);
        self::assertSame(['error.invalid_channel'], $messages);

        $users = $this->createMock(ClearChannelUsersHandlerInterface::class);
        $users->expects(self::never())->method('handle');
        self::assertFalse(new ClearusersCommand($users)->execute($this->context(null, ['#channel']))->success);

        $messages = [];
        self::assertFalse(new ClearusersCommand($users)->execute($this->context($this->sender(), ['invalid'], $messages))->success);
        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function clearAccessPresentsAllOutcomes(): void
    {
        $handler = $this->createStub(ClearChannelAccessHandlerInterface::class);
        $handler->method('handle')->willReturnOnConsecutiveCalls(
            new ClearChannelAccessResult(ClearChannelAccessOutcome::ChannelNotRegistered),
            new ClearChannelAccessResult(ClearChannelAccessOutcome::AlreadyEmpty),
            new ClearChannelAccessResult(ClearChannelAccessOutcome::Cleared, 3),
        );
        $command = new ClearaccessCommand($handler);

        $messages = [];
        self::assertFalse($command->execute($this->context($this->sender(), ['#channel'], $messages))->success);
        self::assertSame(['error.channel_not_registered'], $messages);
        $messages = [];
        self::assertFalse($command->execute($this->context($this->sender(), ['#channel'], $messages))->success);
        self::assertSame(['clearaccess.empty'], $messages);
        $messages = [];
        $outcome = $command->execute($this->context($this->sender(), ['#channel'], $messages));
        self::assertSame(['clearaccess.success'], $messages);
        self::assertInstanceOf(IrcopAuditData::class, $outcome->auditData);
        self::assertSame(['count' => 3], $outcome->auditData->extra);
    }

    #[Test]
    public function clearUsersBuildsReasonAndPresentsAllOutcomes(): void
    {
        $handler = $this->createStub(ClearChannelUsersHandlerInterface::class);
        $handler->method('handle')->willReturnOnConsecutiveCalls(
            new ClearChannelUsersResult(ClearChannelUsersOutcome::ChannelNotRegistered),
            new ClearChannelUsersResult(ClearChannelUsersOutcome::ChannelNotOnNetwork),
            new ClearChannelUsersResult(ClearChannelUsersOutcome::AlreadyEmpty),
            new ClearChannelUsersResult(ClearChannelUsersOutcome::Cleared, 2),
            new ClearChannelUsersResult(ClearChannelUsersOutcome::Cleared, 1),
        );
        $command = new ClearusersCommand($handler);

        foreach (['error.channel_not_registered', 'clearusers.not_on_network', 'clearusers.empty'] as $expected) {
            $messages = [];
            self::assertFalse($command->execute($this->context($this->sender(), ['#channel'], $messages))->success);
            self::assertSame([$expected], $messages);
        }

        $messages = [];
        $outcome = $command->execute($this->context($this->sender(), ['#channel', 'custom', 'reason'], $messages));
        self::assertSame(['clearusers.success'], $messages);
        self::assertInstanceOf(IrcopAuditData::class, $outcome->auditData);
        self::assertSame('custom reason', $outcome->auditData->reason);
        self::assertSame(['kicked_count' => 2], $outcome->auditData->extra);

        $messages = [];
        $outcome = $command->execute($this->context($this->sender(), ['#channel'], $messages));
        self::assertNull($outcome->auditData?->reason);
    }

    private function sender(): SenderView
    {
        return new SenderView('UID1', 'Oper', 'ident', 'host', 'cloak', '*');
    }

    /**
     * @param list<string> $args
     * @param list<string> $messages
     */
    private function context(?SenderView $sender, array $args, array &$messages = []): ChanServContext
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $serviceNick = $this->createStub(ServiceNicknameProviderInterface::class);
        $serviceNick->method('getServiceKey')->willReturn('chanserv');
        $serviceNick->method('getNickname')->willReturn('ChanServ');

        return new ChanServContext(
            $sender,
            null,
            'COMMAND',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ChannelModeSupportInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new ServiceNicknameRegistry([$serviceNick]),
        );
    }
}
