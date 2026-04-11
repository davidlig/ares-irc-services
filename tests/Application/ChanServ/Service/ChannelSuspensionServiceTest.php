<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Service;

use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelSuspensionService::class)]
final class ChannelSuspensionServiceTest extends TestCase
{
    private ChannelServiceActionsPort $channelServiceActions;

    private ChannelLookupPort $channelLookup;

    private ActiveChannelModeSupportProviderInterface $modeSupportProvider;

    private ChannelModeSupportInterface $modeSupport;

    protected function setUp(): void
    {
        $this->channelServiceActions = $this->createMock(ChannelServiceActionsPort::class);
        $this->channelLookup = $this->createStub(ChannelLookupPort::class);
        $this->modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $this->modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $this->modeSupportProvider->method('getSupport')->willReturn($this->modeSupport);
    }

    private function createService(): ChannelSuspensionService
    {
        return new ChannelSuspensionService(
            $this->channelServiceActions,
            $this->channelLookup,
            $this->modeSupportProvider,
        );
    }

    #[Test]
    public function enforceSuspensionRemovesRegisteredAndPermanentModes(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $view = new ChannelView('#test', '+rPnt', 'Topic', 5);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(true);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $this->channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-rP', []);

        $this->channelServiceActions->expects(self::never())
            ->method('kickFromChannel');

        $this->createService()->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionRemovesOnlyRegisteredModeWhenPermanentNotSupported(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $view = new ChannelView('#test', '+rnt', 'Topic', 3);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(false);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

        $this->channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '-r', []);

        $this->channelServiceActions->expects(self::never())
            ->method('kickFromChannel');

        $this->createService()->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionKicksAllUsersFromChannel(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $members = [
            ['uid' => 'UIDAAA', 'roleLetter' => 'o'],
            ['uid' => 'UIDAAB', 'roleLetter' => 'v'],
            ['uid' => 'UIDAAC', 'roleLetter' => ''],
        ];
        $view = new ChannelView('#test', '+rP', null, 3, $members);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(false);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn(null);
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(false);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn(null);

        $this->channelServiceActions->expects(self::never())
            ->method('setChannelModes');

        $this->channelServiceActions->expects(self::exactly(3))
            ->method('kickFromChannel')
            ->willReturnCallback(static function (string $chan, string $uid, string $reason): void {
                self::assertSame('#test', $chan);
                self::assertSame('Channel suspended', $reason);
                self::assertContains($uid, ['UIDAAA', 'UIDAAB', 'UIDAAC']);
            });

        $this->createService()->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionDoesNothingWhenChannelNotOnNetwork(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');

        $this->channelLookup->method('findByChannelName')->willReturn(null);

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');
        $this->channelServiceActions->expects(self::never())->method('kickFromChannel');

        $this->createService()->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionDoesNothingWhenNoModesToRemove(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $view = new ChannelView('#test', '+nt', 'Topic', 2);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(true);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->createService()->enforceSuspension($channel);
    }

    #[Test]
    public function liftSuspensionRestoresRegisteredAndPermanentModes(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $view = new ChannelView('#test', '+nt', 'Topic', 5);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(true);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $this->channelServiceActions->expects(self::once())
            ->method('setChannelModes')
            ->with('#test', '+rP', []);

        $this->createService()->liftSuspension($channel);
    }

    #[Test]
    public function liftSuspensionDoesNothingWhenChannelNotOnNetwork(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');

        $this->channelLookup->method('findByChannelName')->willReturn(null);

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->createService()->liftSuspension($channel);
    }

    #[Test]
    public function liftSuspensionDoesNothingWhenModesAlreadyPresent(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $view = new ChannelView('#test', '+rPnt', 'Topic', 5);

        $this->channelLookup->method('findByChannelName')->willReturn($view);
        $this->modeSupport->method('hasChannelRegisteredMode')->willReturn(true);
        $this->modeSupport->method('getChannelRegisteredModeLetter')->willReturn('r');
        $this->modeSupport->method('hasPermanentChannelMode')->willReturn(true);
        $this->modeSupport->method('getPermanentChannelModeLetter')->willReturn('P');

        $this->channelServiceActions->expects(self::never())->method('setChannelModes');

        $this->createService()->liftSuspension($channel);
    }
}
