<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServAccessChannelDropSubscriber;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelAccess\CleanupChannelAccess;
use App\ChanServ\Application\UseCase\CleanupChannelAccess\CleanupChannelAccessHandler;
use DateTimeImmutable;
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

        $subscriber->onChannelDrop(new ChannelDropCleanupEvent(
            channelId: 12345,
            occurredAt: new DateTimeImmutable('2026-01-02 03:04:05'),
            channelName: '#test',
            channelNameLower: '#test',
            reason: 'manual',
        ));
    }
}
