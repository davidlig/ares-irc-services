<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Infrastructure\ChanServ\Subscriber\ChanServNickDropCleanupSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ChanServNickDropCleanupSubscriber::class)]
final class ChanServNickDropCleanupSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToNickDropEvent(): void
    {
        self::assertSame(
            [NickDropEvent::class => ['onNickDrop', 0]],
            ChanServNickDropCleanupSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function deletesAccessEntriesForDroppedNick(): void
    {
        $channelAccessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $channelAkickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $subscriber = new ChanServNickDropCleanupSubscriber(
            $channelAccessRepository,
            $channelAkickRepository,
            $channelRepository,
            $eventDispatcher,
        );

        $event = new NickDropEvent(
            nickId: 100,
            nickname: 'TestUser',
            nicknameLower: 'testuser',
            reason: 'manual',
        );

        $channelAccessRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(100);

        $channelAkickRepository
            ->expects(self::once())
            ->method('clearCreatorNickId')
            ->with(100);

        $channelRepository
            ->expects(self::once())
            ->method('clearSuccessorNickId')
            ->with(100);

        $channelRepository
            ->expects(self::once())
            ->method('findByFounderNickId')
            ->with(100)
            ->willReturn([]);

        $subscriber->onNickDrop($event);
    }

    #[Test]
    public function transfersChannelToSuccessorWhenFounderDropped(): void
    {
        $channelAccessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $channelAkickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $subscriber = new ChanServNickDropCleanupSubscriber(
            $channelAccessRepository,
            $channelAkickRepository,
            $channelRepository,
            $eventDispatcher,
        );

        $event = new NickDropEvent(
            nickId: 200,
            nickname: 'Founder',
            nicknameLower: 'founder',
            reason: 'inactivity',
        );

        $channel = $this->createMock(RegisteredChannel::class);
        $channel->method('getId')->willReturn(42);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getNameLower')->willReturn('#test');
        $channel->method('getSuccessorNickId')->willReturn(300);

        $channelAccessRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(200);

        $channelAkickRepository
            ->expects(self::once())
            ->method('clearCreatorNickId')
            ->with(200);

        $channelRepository
            ->expects(self::once())
            ->method('clearSuccessorNickId')
            ->with(200);

        $channelRepository
            ->expects(self::once())
            ->method('findByFounderNickId')
            ->with(200)
            ->willReturn([$channel]);

        $channel
            ->expects(self::once())
            ->method('changeFounder')
            ->with(300);

        $channelRepository
            ->expects(self::once())
            ->method('save')
            ->with($channel);

        $subscriber->onNickDrop($event);
    }

    #[Test]
    public function dropsChannelWhenFounderDroppedWithNoSuccessor(): void
    {
        $channelAccessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $channelAkickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $subscriber = new ChanServNickDropCleanupSubscriber(
            $channelAccessRepository,
            $channelAkickRepository,
            $channelRepository,
            $eventDispatcher,
        );

        $event = new NickDropEvent(
            nickId: 400,
            nickname: 'FounderNoSucc',
            nicknameLower: 'foundernosucc',
            reason: 'manual',
        );

        $channel = $this->createMock(RegisteredChannel::class);
        $channel->method('getId')->willReturn(99);
        $channel->method('getName')->willReturn('#orphan');
        $channel->method('getNameLower')->willReturn('#orphan');
        $channel->method('getSuccessorNickId')->willReturn(null);

        $channelAccessRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(400);

        $channelAkickRepository
            ->expects(self::once())
            ->method('clearCreatorNickId')
            ->with(400);

        $channelRepository
            ->expects(self::once())
            ->method('clearSuccessorNickId')
            ->with(400);

        $channelRepository
            ->expects(self::once())
            ->method('findByFounderNickId')
            ->with(400)
            ->willReturn([$channel]);

        $channel
            ->expects(self::never())
            ->method('changeFounder');

        $channelRepository
            ->expects(self::never())
            ->method('save');

        $eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (ChannelDropEvent $dropEvent): bool => 99 === $dropEvent->channelId
                    && '#orphan' === $dropEvent->channelName
                    && 'founder_dropped' === $dropEvent->reason));

        $channelRepository
            ->expects(self::once())
            ->method('delete')
            ->with($channel);

        $subscriber->onNickDrop($event);
    }

    #[Test]
    public function handlesMultipleChannelsForDroppedFounder(): void
    {
        $channelAccessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $channelAkickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        $subscriber = new ChanServNickDropCleanupSubscriber(
            $channelAccessRepository,
            $channelAkickRepository,
            $channelRepository,
            $eventDispatcher,
        );

        $event = new NickDropEvent(
            nickId: 500,
            nickname: 'MultiFounder',
            nicknameLower: 'multifounder',
            reason: 'manual',
        );

        $channelWithSuccessor = $this->createMock(RegisteredChannel::class);
        $channelWithSuccessor->method('getId')->willReturn(10);
        $channelWithSuccessor->method('getName')->willReturn('#withsuccessor');
        $channelWithSuccessor->method('getNameLower')->willReturn('#withsuccessor');
        $channelWithSuccessor->method('getSuccessorNickId')->willReturn(600);

        $channelWithoutSuccessor = $this->createStub(RegisteredChannel::class);
        $channelWithoutSuccessor->method('getId')->willReturn(11);
        $channelWithoutSuccessor->method('getName')->willReturn('#nosuccessor');
        $channelWithoutSuccessor->method('getNameLower')->willReturn('#nosuccessor');
        $channelWithoutSuccessor->method('getSuccessorNickId')->willReturn(null);

        $channelAccessRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(500);

        $channelAkickRepository
            ->expects(self::once())
            ->method('clearCreatorNickId')
            ->with(500);

        $channelRepository
            ->expects(self::once())
            ->method('clearSuccessorNickId')
            ->with(500);

        $channelRepository
            ->expects(self::once())
            ->method('findByFounderNickId')
            ->with(500)
            ->willReturn([$channelWithSuccessor, $channelWithoutSuccessor]);

        $channelWithSuccessor
            ->expects(self::once())
            ->method('changeFounder')
            ->with(600);

        $channelRepository
            ->expects(self::once())
            ->method('save')
            ->with($channelWithSuccessor);

        $eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (ChannelDropEvent $dropEvent): bool => 11 === $dropEvent->channelId && 'founder_dropped' === $dropEvent->reason));

        $channelRepository
            ->expects(self::once())
            ->method('delete')
            ->with($channelWithoutSuccessor);

        $subscriber->onNickDrop($event);
    }

    #[Test]
    public function cleansUpAllReferencesWhenNickDropped(): void
    {
        $channelAccessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $channelAkickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $subscriber = new ChanServNickDropCleanupSubscriber(
            $channelAccessRepository,
            $channelAkickRepository,
            $channelRepository,
            $eventDispatcher,
        );

        $event = new NickDropEvent(
            nickId: 777,
            nickname: 'CleanupTest',
            nicknameLower: 'cleanuptest',
            reason: 'inactivity',
        );

        $channelAccessRepository
            ->expects(self::once())
            ->method('deleteByNickId')
            ->with(777);

        $channelAkickRepository
            ->expects(self::once())
            ->method('clearCreatorNickId')
            ->with(777);

        $channelRepository
            ->expects(self::once())
            ->method('clearSuccessorNickId')
            ->with(777);

        $channelRepository
            ->expects(self::once())
            ->method('findByFounderNickId')
            ->with(777)
            ->willReturn([]);

        $subscriber->onNickDrop($event);
    }
}
