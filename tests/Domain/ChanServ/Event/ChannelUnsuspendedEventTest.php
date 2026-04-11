<?php

declare(strict_types=1);

namespace App\Tests\Domain\ChanServ\Event;

use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelUnsuspendedEvent::class)]
final class ChannelUnsuspendedEventTest extends TestCase
{
    #[Test]
    public function constructionWithAllProperties(): void
    {
        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#Test',
            channelNameLower: '#test',
            performedBy: 'OperUser',
            performedByNickId: 10,
            performedByIp: '192.168.1.1',
            performedByHost: 'user@host',
        );

        self::assertSame(1, $event->channelId);
        self::assertSame('#Test', $event->channelName);
        self::assertSame('#test', $event->channelNameLower);
        self::assertSame('OperUser', $event->performedBy);
        self::assertSame(10, $event->performedByNickId);
        self::assertSame('192.168.1.1', $event->performedByIp);
        self::assertSame('user@host', $event->performedByHost);
        self::assertInstanceOf(DateTimeImmutable::class, $event->occurredAt);
    }

    #[Test]
    public function constructionWithNullNickId(): void
    {
        $event = new ChannelUnsuspendedEvent(
            channelId: 5,
            channelName: '#Channel',
            channelNameLower: '#channel',
            performedBy: 'Admin',
            performedByNickId: null,
            performedByIp: '*',
            performedByHost: 'admin@*',
        );

        self::assertNull($event->performedByNickId);
    }

    #[Test]
    public function occurredAtCanBeSetExplicitly(): void
    {
        $occurredAt = new DateTimeImmutable('2025-06-15 12:00:00');
        $event = new ChannelUnsuspendedEvent(
            channelId: 1,
            channelName: '#Test',
            channelNameLower: '#test',
            performedBy: 'Oper',
            performedByNickId: 1,
            performedByIp: '*',
            performedByHost: '*',
            occurredAt: $occurredAt,
        );

        self::assertSame($occurredAt, $event->occurredAt);
    }
}
