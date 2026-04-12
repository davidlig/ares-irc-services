<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Service\ChannelHistoryService;
use App\Domain\ChanServ\Entity\ChannelHistory;
use App\Domain\ChanServ\Event\ChannelAccessChangedEvent;
use App\Domain\ChanServ\Event\ChannelAkickChangedEvent;
use App\Domain\ChanServ\Event\ChannelFounderChangedEvent;
use App\Domain\ChanServ\Event\ChannelSuccessorChangedEvent;
use App\Domain\ChanServ\Event\ChannelSuspendedEvent;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\ChannelHistoryRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\ChanServ\Subscriber\ChanServHistorySubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServHistorySubscriber::class)]
final class ChanServHistorySubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = ChanServHistorySubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ChannelSuspendedEvent::class, $events);
        self::assertArrayHasKey(ChannelUnsuspendedEvent::class, $events);
        self::assertArrayHasKey(ChannelFounderChangedEvent::class, $events);
        self::assertArrayHasKey(ChannelSuccessorChangedEvent::class, $events);
        self::assertArrayHasKey(ChannelAccessChangedEvent::class, $events);
        self::assertArrayHasKey(ChannelAkickChangedEvent::class, $events);
    }

    #[Test]
    public function onChannelSuspendedRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('SUSPEND', $history->getAction());
                self::assertSame('OperNick', $history->getPerformedBy());
                self::assertSame(456, $history->getPerformedByNickId());
                self::assertSame('Spamming', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('192.168.1.100', $extraData['ip']);
                self::assertSame('oper@example.com', $extraData['host']);
                self::assertSame('7d', $extraData['duration']);
                self::assertSame('2024-01-22 10:30:00', $extraData['expires_at']);

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelSuspendedEvent(
            channelId: 10,
            channelName: '#test',
            channelNameLower: '#test',
            reason: 'Spamming',
            duration: '7d',
            expiresAt: new DateTimeImmutable('2024-01-22 10:30:00'),
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: new DateTimeImmutable('2024-01-15 10:30:00'),
        );

        $subscriber->onChannelSuspended($event);
    }

    #[Test]
    public function onChannelSuspendedWithoutDuration(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('SUSPEND', $history->getAction());
                $extraData = $history->getExtraData();
                self::assertArrayNotHasKey('duration', $extraData);
                self::assertArrayNotHasKey('expires_at', $extraData);

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelSuspendedEvent(
            channelId: 10,
            channelName: '#test',
            channelNameLower: '#test',
            reason: 'Permanent ban',
            duration: null,
            expiresAt: null,
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelSuspended($event);
    }

    #[Test]
    public function onChannelUnsuspendedRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('UNSUSPEND', $history->getAction());
                self::assertSame('OperNick', $history->getPerformedBy());
                self::assertSame(456, $history->getPerformedByNickId());
                self::assertSame('history.message.unsuspend', $history->getMessage());

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelUnsuspendedEvent(
            channelId: 10,
            channelName: '#test',
            channelNameLower: '#test',
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelUnsuspended($event);
    }

    #[Test]
    public function onChannelFounderChangedRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('SET_FOUNDER', $history->getAction());
                self::assertSame('OperNick', $history->getPerformedBy());
                self::assertSame('history.message.founder_changed', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('OldFounder', $extraData['old_founder']);
                self::assertSame('NewFounder', $extraData['new_founder']);

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $oldFounder = $this->createRegisteredNick('OldFounder', 1);
        $newFounder = $this->createRegisteredNick('NewFounder', 2);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static function (int $id) use ($oldFounder, $newFounder): ?RegisteredNick {
            return match ($id) {
                1 => $oldFounder,
                2 => $newFounder,
                default => null,
            };
        });
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelFounderChangedEvent(
            channelId: 10,
            channelName: '#test',
            oldFounderNickId: 1,
            newFounderNickId: 2,
            performedBy: 'OperNick',
            performedByNickId: 3,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            byOperator: true,
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelFounderChanged($event);
    }

    #[Test]
    public function onChannelFounderChangedWithDroppedNicks(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                $extraData = $history->getExtraData();
                self::assertSame('1', $extraData['old_founder']);
                self::assertSame('2', $extraData['new_founder']);

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelFounderChangedEvent(
            channelId: 10,
            channelName: '#test',
            oldFounderNickId: 1,
            newFounderNickId: 2,
            performedBy: 'OperNick',
            performedByNickId: 3,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            byOperator: true,
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelFounderChanged($event);
    }

    #[Test]
    public function onChannelSuccessorChangedRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('SET_SUCCESSOR', $history->getAction());
                self::assertSame('history.message.successor_changed', $history->getMessage());

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $oldSucc = $this->createRegisteredNick('OldSucc', 5);
        $newSucc = $this->createRegisteredNick('NewSucc', 6);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static function (int $id) use ($oldSucc, $newSucc): ?RegisteredNick {
            return match ($id) {
                5 => $oldSucc,
                6 => $newSucc,
                default => null,
            };
        });
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelSuccessorChangedEvent(
            channelId: 10,
            channelName: '#test',
            oldSuccessorNickId: 5,
            newSuccessorNickId: 6,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelSuccessorChanged($event);
    }

    #[Test]
    public function onChannelSuccessorSetWithNoOldSuccessorRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('SET_SUCCESSOR', $history->getAction());
                self::assertSame('history.message.successor_changed', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('(none)', $extraData['old_successor']);

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $newSucc = $this->createRegisteredNick('NewSucc', 6);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static function (int $id) use ($newSucc): ?RegisteredNick {
            return match ($id) {
                6 => $newSucc,
                default => null,
            };
        });
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelSuccessorChangedEvent(
            channelId: 10,
            channelName: '#test',
            oldSuccessorNickId: null,
            newSuccessorNickId: 6,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelSuccessorChanged($event);
    }

    #[Test]
    public function onChannelSuccessorClearedRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('CLEAR_SUCCESSOR', $history->getAction());
                self::assertSame('history.message.successor_cleared', $history->getMessage());

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $oldSucc = $this->createRegisteredNick('OldSucc', 5);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static function (int $id) use ($oldSucc): ?RegisteredNick {
            return match ($id) {
                5 => $oldSucc,
                default => null,
            };
        });
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelSuccessorChangedEvent(
            channelId: 10,
            channelName: '#test',
            oldSuccessorNickId: 5,
            newSuccessorNickId: null,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelSuccessorChanged($event);
    }

    #[Test]
    public function onChannelAccessChangedAddRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('ACCESS_ADD', $history->getAction());
                self::assertSame('history.message.access_add', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('User1', $extraData['target_nickname']);
                self::assertSame('100', $extraData['level']);

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelAccessChangedEvent(
            channelId: 10,
            channelName: '#test',
            action: 'ADD',
            targetNickId: 20,
            targetNickname: 'User1',
            level: 100,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelAccessChanged($event);
    }

    #[Test]
    public function onChannelAccessChangedDelRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('ACCESS_DEL', $history->getAction());
                self::assertSame('history.message.access_del', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('User1', $extraData['target_nickname']);
                self::assertArrayNotHasKey('level', $extraData);

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelAccessChangedEvent(
            channelId: 10,
            channelName: '#test',
            action: 'DEL',
            targetNickId: 20,
            targetNickname: 'User1',
            level: null,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelAccessChanged($event);
    }

    #[Test]
    public function onChannelAkickChangedAddRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('AKICK_ADD', $history->getAction());
                self::assertSame('history.message.akick_add', $history->getMessage());
                $extraData = $history->getExtraData();
                self::assertSame('*!*@bad.isp', $extraData['mask']);
                self::assertSame('Spamming', $extraData['reason']);

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelAkickChangedEvent(
            channelId: 10,
            channelName: '#test',
            action: 'ADD',
            mask: '*!*@bad.isp',
            reason: 'Spamming',
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelAkickChanged($event);
    }

    #[Test]
    public function onChannelAkickChangedDelRecordsHistory(): void
    {
        $historyRepo = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepo->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (ChannelHistory $history) {
                self::assertSame(10, $history->getChannelId());
                self::assertSame('AKICK_DEL', $history->getAction());
                self::assertSame('history.message.akick_del', $history->getMessage());

                return true;
            }));

        $historyService = new ChannelHistoryService($historyRepo);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $subscriber = new ChanServHistorySubscriber($historyService, $nickRepo);

        $event = new ChannelAkickChangedEvent(
            channelId: 10,
            channelName: '#test',
            action: 'DEL',
            mask: '*!*@bad.isp',
            reason: null,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
            occurredAt: new DateTimeImmutable(),
        );

        $subscriber->onChannelAkickChanged($event);
    }

    private function createRegisteredNick(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending(
            nickname: $nickname,
            passwordHash: 'hash',
            email: 'test@example.com',
            language: 'en',
            expiresAt: new DateTimeImmutable('+1 day'),
        );
        $nick->activate();

        return $nick;
    }
}
