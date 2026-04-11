<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Event;

use App\Domain\ChanServ\Event\ChannelSuspendedEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelSuspendedEvent::class)]
final class ChannelSuspendedEventTest extends TestCase
{
    #[Test]
    public function constructionWithAllProperties(): void
    {
        $expiresAt = new DateTimeImmutable('+7 days');
        $event = new ChannelSuspendedEvent(
            channelId: 1,
            channelName: '#Test',
            channelNameLower: '#test',
            reason: 'Abuse',
            duration: '7d',
            expiresAt: $expiresAt,
            performedBy: 'OperUser',
            performedByNickId: 10,
            performedByIp: '192.168.1.1',
            performedByHost: 'user@host',
        );

        self::assertSame(1, $event->channelId);
        self::assertSame('#Test', $event->channelName);
        self::assertSame('#test', $event->channelNameLower);
        self::assertSame('Abuse', $event->reason);
        self::assertSame('7d', $event->duration);
        self::assertSame($expiresAt, $event->expiresAt);
        self::assertSame('OperUser', $event->performedBy);
        self::assertSame(10, $event->performedByNickId);
        self::assertSame('192.168.1.1', $event->performedByIp);
        self::assertSame('user@host', $event->performedByHost);
        self::assertInstanceOf(DateTimeImmutable::class, $event->occurredAt);
    }

    #[Test]
    public function constructionWithNullDurationAndExpiration(): void
    {
        $event = new ChannelSuspendedEvent(
            channelId: 5,
            channelName: '#Channel',
            channelNameLower: '#channel',
            reason: 'Permanent suspension',
            duration: null,
            expiresAt: null,
            performedBy: 'Admin',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: 'admin@*',
        );

        self::assertNull($event->duration);
        self::assertNull($event->expiresAt);
        self::assertNull($event->performedByNickId);
    }

    #[Test]
    public function occurredAtDefaultsToNow(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $event = new ChannelSuspendedEvent(
            channelId: 1,
            channelName: '#Test',
            channelNameLower: '#test',
            reason: 'test',
            duration: null,
            expiresAt: null,
            performedBy: 'Oper',
            performedByNickId: 1,
            performedByIp: '*',
            performedByHost: '*',
        );
        $after = new DateTimeImmutable('+1 second');

        self::assertGreaterThanOrEqual($before, $event->occurredAt);
        self::assertLessThanOrEqual($after, $event->occurredAt);
    }

    #[Test]
    public function occurredAtCanBeSetExplicitly(): void
    {
        $occurredAt = new DateTimeImmutable('2025-06-15 12:00:00');
        $event = new ChannelSuspendedEvent(
            channelId: 1,
            channelName: '#Test',
            channelNameLower: '#test',
            reason: 'test',
            duration: '30d',
            expiresAt: new DateTimeImmutable('2025-07-15'),
            performedBy: 'Oper',
            performedByNickId: 1,
            performedByIp: '*',
            performedByHost: '*',
            occurredAt: $occurredAt,
        );

        self::assertSame($occurredAt, $event->occurredAt);
    }
}
