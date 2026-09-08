<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcChanNetworkActions;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;
use App\Shared\Application\Port\ChannelServiceActionsPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChanNetworkActions::class)]
final class IrcChanNetworkActionsTest extends TestCase
{
    #[Test]
    public function removesRegistrationForPendingDeletionInProtocolOrder(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $matcher = self::exactly(2);
        $actions->expects($matcher)
            ->method('setChannelModes')
            ->willReturnCallback(static function (string $channel, string $modes, array $params, ?int $timestamp) use ($matcher): void {
                self::assertSame('#test', $channel);
                self::assertSame([], $params);
                self::assertSame(12345, $timestamp);
                self::assertSame(1 === $matcher->numberOfInvocations() ? '-r' : '-P', $modes);
            });

        $lookup = $this->createStub(ChannelLookupPort::class);
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getChannelRegisteredModeLetter')->willReturn('r');
        $support->method('getPermanentChannelModeLetter')->willReturn('P');
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->removeRegistrationForPendingDeletion('#test', true, 12345);
    }

    #[Test]
    public function restoresRegistrationAfterPendingDeletionInProtocolOrder(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $matcher = self::exactly(2);
        $actions->expects($matcher)
            ->method('setChannelModes')
            ->willReturnCallback(static function (string $channel, string $modes, array $params, ?int $timestamp) use ($matcher): void {
                self::assertSame('#test', $channel);
                self::assertSame([], $params);
                self::assertSame(12345, $timestamp);
                self::assertSame(1 === $matcher->numberOfInvocations() ? '+r' : '+P', $modes);
            });

        $lookup = $this->createStub(ChannelLookupPort::class);
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getChannelRegisteredModeLetter')->willReturn('r');
        $support->method('getPermanentChannelModeLetter')->willReturn('P');
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->restoreRegistrationAfterPendingDeletion('#test', true, 12345);
    }

    #[Test]
    public function enforcesForbiddenModesWithOriginalTimestamp(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+ntims', [], 12345);

        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->enforceForbiddenModes('#test', 12345);
    }

    #[Test]
    public function delegatesJoinChannelAsService(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())
            ->method('joinChannelAsService')
            ->with('#test', 12345);

        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->joinChannelAsService('#test', 12345);
    }

    #[Test]
    public function delegatesKickFromChannel(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())
            ->method('kickFromChannel')
            ->with('#test', 'UID123', 'Forbidden channel');

        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->kickFromChannel('#test', 'UID123', 'Forbidden channel');
    }

    #[Test]
    public function isChannelOnNetworkReturnsTrueWhenFound(): void
    {
        $actions = $this->createStub(ChannelServiceActionsPort::class);
        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+nt', 'Topic', 1));

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        self::assertTrue($adapter->isChannelOnNetwork('#test'));
    }

    #[Test]
    public function isChannelOnNetworkReturnsFalseWhenNotFound(): void
    {
        $actions = $this->createStub(ChannelServiceActionsPort::class);
        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $lookup->method('findByChannelName')->willReturn(null);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        self::assertFalse($adapter->isChannelOnNetwork('#test'));
    }

    #[Test]
    public function getChannelTimestampReturnsTimestampWhenFound(): void
    {
        $actions = $this->createStub(ChannelServiceActionsPort::class);
        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+nt', 'Topic', 1, [], 999888));

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        self::assertSame(999888, $adapter->getChannelTimestamp('#test'));
    }

    #[Test]
    public function getChannelTimestampReturnsNullWhenNotFound(): void
    {
        $actions = $this->createStub(ChannelServiceActionsPort::class);
        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $lookup->method('findByChannelName')->willReturn(null);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        self::assertNull($adapter->getChannelTimestamp('#test'));
    }

    #[Test]
    public function getChannelMemberUidsReturnsUidsWhenFound(): void
    {
        $actions = $this->createStub(ChannelServiceActionsPort::class);
        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $members = [
            ['uid' => 'UID1', 'roleLetter' => 'o'],
            ['uid' => 'UID2', 'roleLetter' => 'v'],
        ];
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+nt', 'Topic', 2, $members));

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        self::assertSame(['UID1', 'UID2'], $adapter->getChannelMemberUids('#test'));
    }

    #[Test]
    public function getChannelMemberUidsReturnsEmptyArrayWhenNotFound(): void
    {
        $actions = $this->createStub(ChannelServiceActionsPort::class);
        $lookup = $this->createStub(ChannelLookupPort::class);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $lookup->method('findByChannelName')->willReturn(null);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        self::assertSame([], $adapter->getChannelMemberUids('#test'));
    }

    #[Test]
    public function removeRegistrationModesRemovesModesWhenFound(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-rP', [], 12345);

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+rPnt', 'Topic', 1, [], 12345));

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($modeSupport);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->removeRegistrationModes('#test');
    }

    #[Test]
    public function removeRegistrationModesDoesNothingWhenChannelNotFound(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('setChannelModes');

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(null);

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->removeRegistrationModes('#test');
    }

    #[Test]
    public function removeRegistrationModesDoesNothingWhenNoModesToRemove(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('setChannelModes');

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+nt', 'Topic', 1));

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($modeSupport);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->removeRegistrationModes('#test');
    }

    #[Test]
    public function removeRegistrationModesIgnoresNullLetters(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('setChannelModes');

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+rPnt', 'Topic', 1));

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($modeSupport);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->removeRegistrationModes('#test');
    }

    #[Test]
    public function restoreRegistrationModesSetsModesWhenMissing(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+rP', [], 12345);

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+nt', 'Topic', 1, [], 12345));

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($modeSupport);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->restoreRegistrationModes('#test');
    }

    #[Test]
    public function restoreRegistrationModesDoesNothingWhenChannelNotFound(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('setChannelModes');

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(null);

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->restoreRegistrationModes('#test');
    }

    #[Test]
    public function restoreRegistrationModesDoesNothingWhenModesAlreadyPresent(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('setChannelModes');

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+rPnt', 'Topic', 1));

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($modeSupport);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->restoreRegistrationModes('#test');
    }

    #[Test]
    public function restoreRegistrationModesIgnoresNullLetters(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::never())->method('setChannelModes');

        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#test', '+nt', 'Topic', 1));

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($modeSupport);

        $adapter = new IrcChanNetworkActions($actions, $lookup, $provider);
        $adapter->restoreRegistrationModes('#test');
    }

    #[Test]
    public function partsChannelAsService(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())->method('partChannelAsService')->with('#test');

        $adapter = new IrcChanNetworkActions(
            $actions,
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );
        $adapter->partChannelAsService('#test');
    }
}
