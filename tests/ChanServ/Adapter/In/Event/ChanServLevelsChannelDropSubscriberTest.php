<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServLevelsChannelDropSubscriber;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelLevels\CleanupChannelLevels;
use App\ChanServ\Application\UseCase\CleanupChannelLevels\CleanupChannelLevelsHandler;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServLevelsChannelDropSubscriber::class)]
#[CoversClass(CleanupChannelLevels::class)]
#[CoversClass(CleanupChannelLevelsHandler::class)]
final class ChanServLevelsChannelDropSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToChannelDropEvent(): void
    {
        self::assertSame(
            [ChannelDropCleanupEvent::class => ['onChannelDrop', 0]],
            ChanServLevelsChannelDropSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function mapsPublishedEventToTypedCleanupRequest(): void
    {
        $repository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $repository->expects(self::once())->method('removeAllForChannel')->with(12345);
        $subscriber = new ChanServLevelsChannelDropSubscriber(new CleanupChannelLevelsHandler($repository));

        $subscriber->onChannelDrop(new ChannelDropCleanupEvent(
            channelId: 12345,
            occurredAt: new DateTimeImmutable('2026-01-02 03:04:05'),
            channelName: '#test',
            channelNameLower: '#test',
            reason: 'manual',
        ));
    }
}
