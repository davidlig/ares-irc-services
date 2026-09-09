<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Entity;

use App\ChanServ\Domain\Entity\ChannelHistory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelHistory::class)]
final class ChannelHistoryTest extends TestCase
{
    #[Test]
    public function recordCreatesHistoryEntry(): void
    {
        $performedAt = new DateTimeImmutable('2024-01-15 10:30:00');

        $history = ChannelHistory::record(
            channelId: 42,
            action: 'SUSPEND',
            performedBy: 'Operator1',
            performedByNickId: 10,
            message: 'Suspended for rule violation',
            extraData: ['duration' => '30d'],
            performedAt: $performedAt,
        );

        self::assertSame(0, $history->getId());
        self::assertSame(42, $history->getChannelId());
        self::assertSame('SUSPEND', $history->getAction());
        self::assertSame('Operator1', $history->getPerformedBy());
        self::assertSame(10, $history->getPerformedByNickId());
        self::assertSame($performedAt, $history->getPerformedAt());
        self::assertSame('Suspended for rule violation', $history->getMessage());
        self::assertSame(['duration' => '30d'], $history->getExtraData());
    }

    #[Test]
    public function recordWithNullPerformedByNickId(): void
    {
        $history = ChannelHistory::record(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            channelId: 42,
            action: 'SET_FOUNDER',
            performedBy: 'UnregisteredOper',
            performedByNickId: null,
            message: 'Founder changed',
        );

        self::assertNull($history->getPerformedByNickId());
    }

    #[Test]
    public function recordWithExplicitPerformedAt(): void
    {
        $performedAt = new DateTimeImmutable('2026-01-02 03:04:05');

        $history = ChannelHistory::record(
            $performedAt,
            channelId: 1,
            action: 'HISTORY_ADD',
            performedBy: 'Admin',
            performedByNickId: 5,
            message: 'Manual note',
        );

        self::assertSame($performedAt, $history->getPerformedAt());
    }

    #[Test]
    public function recordWithEmptyExtraData(): void
    {
        $history = ChannelHistory::record(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            channelId: 1,
            action: 'UNSUSPEND',
            performedBy: 'Admin',
            performedByNickId: 5,
            message: 'Suspension lifted',
            extraData: [],
        );

        self::assertSame([], $history->getExtraData());
    }

    #[Test]
    public function toArrayReturnsCorrectFormat(): void
    {
        $performedAt = new DateTimeImmutable('2024-01-15 10:30:00');

        $history = ChannelHistory::record(
            channelId: 42,
            action: 'AKICK_ADD',
            performedBy: 'User',
            performedByNickId: null,
            performedAt: $performedAt,
            message: 'AKICK added',
            extraData: ['mask' => '*!*@bad.isp'],
        );

        $array = $history->toArray();

        self::assertSame(0, $array['id']);
        self::assertSame(42, $array['channel_id']);
        self::assertSame('AKICK_ADD', $array['action']);
        self::assertSame('User', $array['performed_by']);
        self::assertNull($array['performed_by_nick_id']);
        self::assertSame('2024-01-15T10:30:00+00:00', $array['performed_at']);
        self::assertSame('AKICK added', $array['message']);
        self::assertSame(['mask' => '*!*@bad.isp'], $array['extra_data']);
    }

    #[Test]
    public function constructorAcceptsPersistedId(): void
    {
        $history = new ChannelHistory(
            id: 100,
            channelId: 42,
            action: 'TEST',
            performedBy: 'Admin',
            performedByNickId: 5,
            performedAt: new DateTimeImmutable('2024-01-15 10:30:00'),
            message: 'Test message',
        );

        self::assertSame(100, $history->getId());
    }
}
