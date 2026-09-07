<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChannelSuspensionNotifier;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelSuspensionService::class)]
final class ChannelSuspensionServiceTest extends TestCase
{
    /** @var ChanNetworkActions&Stub */
    private ChanNetworkActions $channelActions;

    /** @var ChannelSuspensionNotifier&Stub */
    private ChannelSuspensionNotifier $suspensionNotifier;

    /** @var ChanServActivitySink&Stub */
    private ChanServActivitySink $logger;

    protected function setUp(): void
    {
        $this->channelActions = $this->createStub(ChanNetworkActions::class);
        $this->suspensionNotifier = $this->createStub(ChannelSuspensionNotifier::class);
        $this->logger = $this->createStub(ChanServActivitySink::class);
    }

    private function createService(
        ?ChanNetworkActions $channelActions = null,
        ?ChannelSuspensionNotifier $suspensionNotifier = null,
        ?ChanServActivitySink $logger = null,
    ): ChannelSuspensionService {
        return new ChannelSuspensionService(
            $channelActions ?? $this->channelActions,
            $suspensionNotifier ?? $this->suspensionNotifier,
            $logger ?? $this->logger,
        );
    }

    #[Test]
    public function enforceSuspensionRemovesRegistrationModesAndSendsNotice(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('Abuse');

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('removeRegistrationModes')
            ->with('#test');

        $suspensionNotifier = $this->createMock(ChannelSuspensionNotifier::class);
        $suspensionNotifier->expects(self::once())
            ->method('notifyChannelSuspended')
            ->with('#test', 'Abuse');

        $this->createService($channelActions, $suspensionNotifier)->enforceSuspension($channel);
    }

    #[Test]
    public function enforceSuspensionPassesEmptyStringForEmptyReason(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channel->suspend('');

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('removeRegistrationModes')
            ->with('#test');

        $suspensionNotifier = $this->createMock(ChannelSuspensionNotifier::class);
        $suspensionNotifier->expects(self::once())
            ->method('notifyChannelSuspended')
            ->with('#test', '');

        $this->createService($channelActions, $suspensionNotifier)->enforceSuspension($channel);
    }

    #[Test]
    public function liftSuspensionRestoresRegistrationModes(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');

        $channelActions = $this->createMock(ChanNetworkActions::class);
        $channelActions->expects(self::once())
            ->method('restoreRegistrationModes')
            ->with('#test');

        $this->createService($channelActions)->liftSuspension($channel);
    }
}
