<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Event;

use App\Domain\NickServ\Entity\NickHistory;
use App\Domain\NickServ\Event\NickEmailChangedEvent;
use App\Domain\NickServ\Event\NickHistoryRecordedEvent;
use App\Domain\NickServ\Event\NickPasswordChangedEvent;
use App\Domain\NickServ\Event\NickRecoveredEvent;
use App\Domain\NickServ\Event\NickSuspendedEvent;
use App\Domain\NickServ\Event\NickUnsuspendedEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickEmailChangedEvent::class)]
#[CoversClass(NickHistoryRecordedEvent::class)]
#[CoversClass(NickPasswordChangedEvent::class)]
#[CoversClass(NickRecoveredEvent::class)]
#[CoversClass(NickSuspendedEvent::class)]
#[CoversClass(NickUnsuspendedEvent::class)]
final class NickHistoryEventsTest extends TestCase
{
    #[Test]
    public function nickSuspendedEventCanBeConstructed(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new NickSuspendedEvent(
            nickId: 123,
            nickname: 'TestNick',
            reason: 'Spamming',
            duration: '7d',
            expiresAt: new DateTimeImmutable('2024-01-22 10:30:00'),
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: $occurredAt,
        );

        self::assertSame(123, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
        self::assertSame('Spamming', $event->reason);
        self::assertSame('7d', $event->duration);
        self::assertSame('2024-01-22 10:30:00', $event->expiresAt->format('Y-m-d H:i:s'));
        self::assertSame('OperNick', $event->performedBy);
        self::assertSame(456, $event->performedByNickId);
        self::assertSame('192.168.1.100', $event->performedByIp);
        self::assertSame('oper@example.com', $event->performedByHost);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function nickSuspendedEventWithNullDurationCanBeConstructed(): void
    {
        $event = new NickSuspendedEvent(
            nickId: 123,
            nickname: 'TestNick',
            reason: 'Permanent ban',
            duration: null,
            expiresAt: null,
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
        );

        self::assertSame(123, $event->nickId);
        self::assertNull($event->duration);
        self::assertNull($event->expiresAt);
    }

    #[Test]
    public function nickUnsuspendedEventCanBeConstructed(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new NickUnsuspendedEvent(
            nickId: 123,
            nickname: 'TestNick',
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: $occurredAt,
        );

        self::assertSame(123, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
        self::assertSame('OperNick', $event->performedBy);
        self::assertSame(456, $event->performedByNickId);
        self::assertSame('192.168.1.100', $event->performedByIp);
        self::assertSame('oper@example.com', $event->performedByHost);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function nickPasswordChangedEventCanBeConstructed(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new NickPasswordChangedEvent(
            nickId: 123,
            nickname: 'TestNick',
            changedByOwner: true,
            performedBy: 'TestNick',
            performedByNickId: 123,
            performedByIp: '192.168.1.100',
            performedByHost: 'test@example.com',
            occurredAt: $occurredAt,
        );

        self::assertSame(123, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
        self::assertTrue($event->changedByOwner);
        self::assertSame('TestNick', $event->performedBy);
        self::assertSame(123, $event->performedByNickId);
        self::assertSame('192.168.1.100', $event->performedByIp);
        self::assertSame('test@example.com', $event->performedByHost);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function nickPasswordChangedEventByIrcopCanBeConstructed(): void
    {
        $event = new NickPasswordChangedEvent(
            nickId: 123,
            nickname: 'TestNick',
            changedByOwner: false,
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
        );

        self::assertFalse($event->changedByOwner);
        self::assertSame('OperNick', $event->performedBy);
        self::assertSame(456, $event->performedByNickId);
    }

    #[Test]
    public function nickEmailChangedEventCanBeConstructed(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new NickEmailChangedEvent(
            nickId: 123,
            nickname: 'TestNick',
            oldEmail: 'old@example.com',
            newEmail: 'new@example.com',
            changedByOwner: true,
            performedBy: 'TestNick',
            performedByNickId: 123,
            performedByIp: '192.168.1.100',
            performedByHost: 'test@example.com',
            occurredAt: $occurredAt,
        );

        self::assertSame(123, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
        self::assertSame('old@example.com', $event->oldEmail);
        self::assertSame('new@example.com', $event->newEmail);
        self::assertTrue($event->changedByOwner);
        self::assertSame('TestNick', $event->performedBy);
        self::assertSame(123, $event->performedByNickId);
        self::assertSame('192.168.1.100', $event->performedByIp);
        self::assertSame('test@example.com', $event->performedByHost);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function nickEmailChangedEventWithNullOldEmailCanBeConstructed(): void
    {
        $event = new NickEmailChangedEvent(
            nickId: 123,
            nickname: 'TestNick',
            oldEmail: null,
            newEmail: 'new@example.com',
            changedByOwner: false,
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
        );

        self::assertNull($event->oldEmail);
        self::assertSame('new@example.com', $event->newEmail);
        self::assertFalse($event->changedByOwner);
    }

    #[Test]
    public function nickRecoveredEventCanBeConstructed(): void
    {
        $occurredAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $event = new NickRecoveredEvent(
            nickId: 123,
            nickname: 'TestNick',
            method: 'email',
            performedBy: 'OperNick',
            performedByNickId: 456,
            performedByIp: '192.168.1.100',
            performedByHost: 'oper@example.com',
            occurredAt: $occurredAt,
        );

        self::assertSame(123, $event->nickId);
        self::assertSame('TestNick', $event->nickname);
        self::assertSame('email', $event->method);
        self::assertSame('OperNick', $event->performedBy);
        self::assertSame(456, $event->performedByNickId);
        self::assertSame('192.168.1.100', $event->performedByIp);
        self::assertSame('oper@example.com', $event->performedByHost);
        self::assertSame($occurredAt, $event->occurredAt);
    }

    #[Test]
    public function nickHistoryRecordedEventCanBeConstructed(): void
    {
        $performedAt = new DateTimeImmutable('2024-01-15 10:30:00');
        $history = NickHistory::record(
            nickId: 123,
            action: 'SUSPEND',
            performedBy: 'OperNick',
            performedByNickId: 456,
            message: 'Spamming',
            extraData: ['duration' => '7d'],
            performedAt: $performedAt,
        );

        $event = new NickHistoryRecordedEvent($history);

        self::assertSame($history, $event->history);
        self::assertSame(123, $event->history->getNickId());
        self::assertSame('SUSPEND', $event->history->getAction());
        self::assertSame('OperNick', $event->history->getPerformedBy());
        self::assertSame(456, $event->history->getPerformedByNickId());
        self::assertSame('Spamming', $event->history->getMessage());
        self::assertSame(['duration' => '7d'], $event->history->getExtraData());
    }
}
