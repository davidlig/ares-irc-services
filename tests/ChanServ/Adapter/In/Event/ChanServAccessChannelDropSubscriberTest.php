<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServAccessChannelDropSubscriber;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelAccess\CleanupChannelAccess;
use App\ChanServ\Application\UseCase\CleanupChannelAccess\CleanupChannelAccessHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServAccessChannelDropSubscriber::class)]
#[CoversClass(CleanupChannelAccess::class)]
#[CoversClass(CleanupChannelAccessHandler::class)]
final class ChanServAccessChannelDropSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToChannelDropEvent(): void
    {
        self::assertSame(
            [ChannelDropCleanupEvent::class => ['onChannelDrop', 0]],
            ChanServAccessChannelDropSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function mapsPublishedEventToTypedCleanupRequest(): void
    {
        $repository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $repository->expects(self::once())->method('deleteByChannelId')->with(12345);
        $subscriber = new ChanServAccessChannelDropSubscriber(new CleanupChannelAccessHandler($repository));

        $subscriber->onChannelDrop(new ChannelDropCleanupEvent(12345, '#test', '#test', 'manual'));
    }
}
