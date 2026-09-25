<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\Irc\Adapter\In\Event\ProtocolOperatorOnlyChannelControl;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\OperatorOnlyModeManagedByOptions;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ServiceUidProviderInterface;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolOperatorOnlyChannelControl::class)]
#[UsesClass(ServiceUidRegistry::class)]
#[UsesClass(ChannelView::class)]
final class ProtocolOperatorOnlyChannelControlTest extends TestCase
{
    #[Test]
    public function activationJoinsChanServAndSetsNativeMode(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())->method('joinChannelAsService')->with('#ops', 123);
        $actions->expects(self::once())->method('setChannelModes')->with('#ops', '+O', [], 123);

        $control = $this->control($actions, new ChannelView('#ops', '+nt', null, 1, [], 123));
        $control->activate('#ops');
    }

    #[Test]
    public function activationSetsModeForNewChannelAfterJoining(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())->method('joinChannelAsService')->with('#ops', null);
        $actions->expects(self::once())->method('setChannelModes')->with('#ops', '+O', [], null);

        $this->control($actions, null)->activate('#ops');
    }

    #[Test]
    public function activationLeavesExistingChanServAndModeAlone(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('joinChannelAsService');
        $actions->expects(self::never())->method('setChannelModes');

        $view = new ChannelView('#ops', '+O', null, 1, [['uid' => 'CS1', 'roleLetter' => 'q']], 123);
        $this->control($actions, $view)->activate('#ops');
    }

    #[Test]
    public function activationUsesUdbOptionsInsteadOfSendingMode(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())->method('joinChannelAsService');
        $actions->expects(self::never())->method('setChannelModes');

        $this->control($actions, new ChannelView('#ops', '+nt', null, 1), true)->activate('#ops');
    }

    #[Test]
    public function deactivationRemovesModeAndPartsBot(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())->method('setChannelModes')->with('#ops', '-O', [], 123);
        $actions->expects(self::once())->method('partChannelAsService')->with('#ops');

        $view = new ChannelView('#ops', '+O', null, 1, [['uid' => 'CS1', 'roleLetter' => 'q']], 123);
        $this->control($actions, $view)->deactivate('#ops');
    }

    #[Test]
    public function deactivationKeepsBotInFixedDebugChannel(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())->method('setChannelModes')->with('#OPS', '-O');
        $actions->expects(self::never())->method('partChannelAsService');

        $view = new ChannelView('#OPS', '+O', null, 1, [['uid' => 'CS1', 'roleLetter' => 'q']]);
        $this->control($actions, $view, false, '#ops')->deactivate('#OPS');
    }

    #[Test]
    public function udbDeactivationOnlyPartsBot(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('setChannelModes');
        $actions->expects(self::once())->method('partChannelAsService');

        $view = new ChannelView('#ops', '+O', null, 1, [['uid' => 'CS1', 'roleLetter' => 'q']]);
        $this->control($actions, $view, true)->deactivate('#ops');
    }

    #[Test]
    public function deactivationDoesNothingWhenChannelIsAbsent(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('setChannelModes');
        $actions->expects(self::never())->method('partChannelAsService');

        $this->control($actions, null)->deactivate('#ops');
    }

    private function control(ChannelServiceActionsPort $actions, ?ChannelView $view, bool $udb = false, ?string $debugChannel = null): ProtocolOperatorOnlyChannelControl
    {
        $channels = $this->createStub(ChannelLookupPort::class);
        $channels->method('findByChannelName')->willReturn($view);
        $provider = $this->createStub(ServiceUidProviderInterface::class);
        $provider->method('getUid')->willReturn('CS1');
        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($this->createStub($udb ? OperatorOnlyModeManagedByOptions::class : ProtocolModuleInterface::class));

        return new ProtocolOperatorOnlyChannelControl($actions, $channels, new ServiceUidRegistry(['chanserv' => $provider]), $holder, $debugChannel);
    }
}
