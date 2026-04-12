<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Event;

use App\Domain\ChanServ\Event\ChannelAccessChangedEvent;
use App\Domain\ChanServ\Event\ChannelAkickChangedEvent;
use App\Domain\ChanServ\Event\ChannelFounderChangedEvent;
use App\Domain\ChanServ\Event\ChannelSuccessorChangedEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelFounderChangedEvent::class)]
#[CoversClass(ChannelSuccessorChangedEvent::class)]
#[CoversClass(ChannelAccessChangedEvent::class)]
#[CoversClass(ChannelAkickChangedEvent::class)]
final class ChannelHistoryEventsTest extends TestCase
{
    #[Test]
    public function channelFounderChangedEventCanBeConstructed(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
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
            occurredAt: $occurredAt,
        );

        self::assertSame(10, $event->channelId);
        self::assertSame('#test', $event->channelName);
        self::assertSame(1, $event->oldFounderNickId);
        self::assertSame(2, $event->newFounderNickId);
        self::assertSame('OperNick', $event->performedBy);
        self::assertSame(3, $event->performedByNickId);
        self::assertSame('192.168.1.100', $event->performedByIp);
        self::assertSame('oper@example.com', $event->performedByHost);
        self::assertTrue($event->byOperator);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function channelFounderChangedEventNotByOperator(): void
    {
        $event = new ChannelFounderChangedEvent(
            channelId: 10,
            channelName: '#test',
            oldFounderNickId: 1,
            newFounderNickId: 2,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
            byOperator: false,
        );

        self::assertFalse($event->byOperator);
    }

    #[Test]
    public function channelSuccessorChangedEventCanBeConstructed(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new ChannelSuccessorChangedEvent(
            channelId: 10,
            channelName: '#test',
            oldSuccessorNickId: 5,
            newSuccessorNickId: 6,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '192.168.1.100',
            performedByHost: 'founder@host',
            occurredAt: $occurredAt,
        );

        self::assertSame(10, $event->channelId);
        self::assertSame('#test', $event->channelName);
        self::assertSame(5, $event->oldSuccessorNickId);
        self::assertSame(6, $event->newSuccessorNickId);
        self::assertSame('Founder', $event->performedBy);
        self::assertSame(1, $event->performedByNickId);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function channelSuccessorChangedEventCleared(): void
    {
        $event = new ChannelSuccessorChangedEvent(
            channelId: 10,
            channelName: '#test',
            oldSuccessorNickId: 5,
            newSuccessorNickId: null,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '10.0.0.1',
            performedByHost: 'founder@host',
        );

        self::assertSame(5, $event->oldSuccessorNickId);
        self::assertNull($event->newSuccessorNickId);
    }

    #[Test]
    public function channelAccessChangedEventAdd(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new ChannelAccessChangedEvent(
            channelId: 10,
            channelName: '#test',
            action: 'ADD',
            targetNickId: 20,
            targetNickname: 'User1',
            level: 100,
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '192.168.1.100',
            performedByHost: 'founder@host',
            occurredAt: $occurredAt,
        );

        self::assertSame(10, $event->channelId);
        self::assertSame('#test', $event->channelName);
        self::assertSame('ADD', $event->action);
        self::assertSame(20, $event->targetNickId);
        self::assertSame('User1', $event->targetNickname);
        self::assertSame(100, $event->level);
        self::assertSame('Founder', $event->performedBy);
        self::assertSame(1, $event->performedByNickId);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function channelAccessChangedEventDel(): void
    {
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
        );

        self::assertSame('DEL', $event->action);
        self::assertNull($event->level);
    }

    #[Test]
    public function channelAkickChangedEventAdd(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new ChannelAkickChangedEvent(
            channelId: 10,
            channelName: '#test',
            action: 'ADD',
            mask: '*!*@bad.isp',
            reason: 'Spamming',
            performedBy: 'Founder',
            performedByNickId: 1,
            performedByIp: '192.168.1.100',
            performedByHost: 'founder@host',
            occurredAt: $occurredAt,
        );

        self::assertSame(10, $event->channelId);
        self::assertSame('#test', $event->channelName);
        self::assertSame('ADD', $event->action);
        self::assertSame('*!*@bad.isp', $event->mask);
        self::assertSame('Spamming', $event->reason);
        self::assertSame('Founder', $event->performedBy);
        self::assertSame(1, $event->performedByNickId);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function channelAkickChangedEventDelWithNullReason(): void
    {
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
        );

        self::assertSame('DEL', $event->action);
        self::assertNull($event->reason);
    }
}
