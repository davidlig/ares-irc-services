<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServAkickChannelDropSubscriber;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelAkick\CleanupChannelAkick;
use App\ChanServ\Application\UseCase\CleanupChannelAkick\CleanupChannelAkickHandler;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServAkickChannelDropSubscriber::class)]
#[CoversClass(CleanupChannelAkick::class)]
#[CoversClass(CleanupChannelAkickHandler::class)]
final class ChanServAkickChannelDropSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToChannelDropEvent(): void
    {
        self::assertSame(
            [ChannelDropCleanupEvent::class => ['onChannelDrop', 0]],
            ChanServAkickChannelDropSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function mapsPublishedEventToTypedCleanupRequest(): void
    {
        $repository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $repository->expects(self::once())->method('deleteByChannelId')->with(12345);
        $subscriber = new ChanServAkickChannelDropSubscriber(new CleanupChannelAkickHandler($repository));

        $subscriber->onChannelDrop(new ChannelDropCleanupEvent(
            channelId: 12345,
            occurredAt: new DateTimeImmutable('2026-01-02 03:04:05'),
            channelName: '#test',
            channelNameLower: '#test',
            reason: 'manual',
        ));
    }
}
