<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServNickDropCleanupSubscriber;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\NickDropCleanupActivitySink;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickData;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickDataHandler;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServNickDropCleanupSubscriber::class)]
#[CoversClass(CleanupDroppedNickData::class)]
#[CoversClass(CleanupDroppedNickDataHandler::class)]
final class ChanServNickDropCleanupSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToNickDropEvent(): void
    {
        self::assertSame(
            [NickDropCleanupEvent::class => ['onNickDrop', 0]],
            ChanServNickDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function mapsPublishedEventToTypedCleanupRequest(): void
    {
        $accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepository->expects(self::once())->method('deleteByNickId')->with(12345);
        $akickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepository->expects(self::once())->method('clearCreatorNickId')->with(12345);
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('clearSuccessorNickId')->with(12345);
        $channelRepository->expects(self::once())->method('findByFounderNickId')->with(12345)->willReturn([]);

        $handler = new CleanupDroppedNickDataHandler(
            $accessRepository,
            $akickRepository,
            $channelRepository,
            $this->createStub(ChanServEventPublisher::class),
            $this->createStub(ChanTransactionBoundary::class),
            $this->createStub(NickDropCleanupActivitySink::class),
        );
        $subscriber = new ChanServNickDropCleanupSubscriber($handler);

        $subscriber->onNickDrop(new NickDropCleanupEvent(
            nickId: 12345,
            nickname: 'TestUser',
            nicknameLower: 'testuser',
            reason: 'manual',
            occurredAt: new DateTimeImmutable(),
        ));
    }
}
