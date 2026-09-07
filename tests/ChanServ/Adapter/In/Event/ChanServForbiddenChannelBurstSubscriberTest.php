<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServForbiddenChannelBurstSubscriber;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServForbiddenChannelBurstSubscriber::class)]
final class ChanServForbiddenChannelBurstSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToCorrectEvents(): void
    {
        self::assertSame(
            [NetworkSynchronizationCompletedEvent::class => ['onNetworkSyncComplete', 10]],
            ChanServForbiddenChannelBurstSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function onNetworkSyncCompleteEnforcesForbiddenChannelsOnNetwork(): void
    {
        $forbidden1 = $this->createStub(RegisteredChannel::class);
        $forbidden1->method('getName')->willReturn('#bad1');

        $forbidden2 = $this->createStub(RegisteredChannel::class);
        $forbidden2->method('getName')->willReturn('#bad2');

        $view1 = new ChannelView('#bad1', '+nt', null, 1);
        $view2 = new ChannelView('#bad2', '+nt', null, 2);

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findForbiddenChannels')->willReturn([$forbidden1, $forbidden2]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturnMap([
            ['#bad1', $view1],
            ['#bad2', $view2],
        ]);

        $enforcedChannels = [];
        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::exactly(2))->method('enforceForbiddenChannel')
            ->willReturnCallback(static function (string $channelName) use (&$enforcedChannels): void {
                $enforcedChannels[] = $channelName;
            });

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber = new ChanServForbiddenChannelBurstSubscriber($channelRepository, $channelLookup, $forbiddenService);
        $subscriber->onNetworkSyncComplete($event);

        self::assertSame(['#bad1', '#bad2'], $enforcedChannels);
    }

    #[Test]
    public function onNetworkSyncCompleteDoesNothingWhenNoForbiddenChannels(): void
    {
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findForbiddenChannels')->willReturn([]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('enforceForbiddenChannel');

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber = new ChanServForbiddenChannelBurstSubscriber($channelRepository, $channelLookup, $forbiddenService);
        $subscriber->onNetworkSyncComplete($event);
    }

    #[Test]
    public function onNetworkSyncCompleteSkipsForbiddenChannelNotOnNetwork(): void
    {
        $forbiddenChannel = $this->createStub(RegisteredChannel::class);
        $forbiddenChannel->method('getName')->willReturn('#offline');

        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findForbiddenChannels')->willReturn([$forbiddenChannel]);

        $channelLookup = $this->createMock(ChannelLookupPort::class);
        $channelLookup->expects(self::once())->method('findByChannelName')->with('#offline')->willReturn(null);

        $forbiddenService = $this->createMock(ChannelForbiddenService::class);
        $forbiddenService->expects(self::never())->method('enforceForbiddenChannel');

        $event = new NetworkSynchronizationCompletedEvent('001');

        $subscriber = new ChanServForbiddenChannelBurstSubscriber($channelRepository, $channelLookup, $forbiddenService);
        $subscriber->onNetworkSyncComplete($event);
    }
}
