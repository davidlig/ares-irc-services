<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServHistoryChannelDropSubscriber;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServHistoryChannelDropSubscriber::class)]
final class ChanServHistoryChannelDropSubscriberTest extends TestCase
{
    private ChannelHistoryRepositoryInterface&MockObject $historyRepository;

    private ChanServHistoryChannelDropSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->historyRepository = $this->createMock(ChannelHistoryRepositoryInterface::class);

        $this->subscriber = new ChanServHistoryChannelDropSubscriber(
            $this->historyRepository,
        );
    }

    #[Test]
    public function subscribesToChannelDropEvent(): void
    {
        $this->historyRepository->expects(self::never())->method('deleteByChannelId');

        self::assertSame(
            [ChannelDropCleanupEvent::class => ['onChannelDrop', 0]],
            ChanServHistoryChannelDropSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesHistoryForDroppedChannel(): void
    {
        $event = new ChannelDropCleanupEvent(
            channelId: 12345,
            channelName: '#test',
            channelNameLower: '#test',
            reason: 'manual',
        );

        $this->historyRepository
            ->expects(self::once())
            ->method('deleteByChannelId')
            ->with(12345);

        $this->subscriber->onChannelDrop($event);
    }

    #[Test]
    public function deletesHistoryForDifferentChannelIds(): void
    {
        $event = new ChannelDropCleanupEvent(
            channelId: 999,
            channelName: '#other',
            channelNameLower: '#other',
            reason: 'inactivity',
        );

        $this->historyRepository
            ->expects(self::once())
            ->method('deleteByChannelId')
            ->with(999);

        $this->subscriber->onChannelDrop($event);
    }
}
