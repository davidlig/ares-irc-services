<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServHistoryChannelDropSubscriber;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelHistory\CleanupChannelHistory;
use App\ChanServ\Application\UseCase\CleanupChannelHistory\CleanupChannelHistoryHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServHistoryChannelDropSubscriber::class)]
#[CoversClass(CleanupChannelHistory::class)]
#[CoversClass(CleanupChannelHistoryHandler::class)]
final class ChanServHistoryChannelDropSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToChannelDropEvent(): void
    {
        self::assertSame(
            [ChannelDropCleanupEvent::class => ['onChannelDrop', 0]],
            ChanServHistoryChannelDropSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function mapsPublishedEventToTypedCleanupRequest(): void
    {
        $repository = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $repository->expects(self::once())->method('deleteByChannelId')->with(999);
        $subscriber = new ChanServHistoryChannelDropSubscriber(new CleanupChannelHistoryHandler($repository));

        $subscriber->onChannelDrop(new ChannelDropCleanupEvent(999, '#other', '#other', 'inactivity'));
    }
}
