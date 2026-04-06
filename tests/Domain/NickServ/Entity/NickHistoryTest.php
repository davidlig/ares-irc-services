<?php

declare(strict_types=1);

namespace App\Tests\Domain\NickServ\Entity;

use App\Domain\NickServ\Entity\NickHistory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickHistory::class)]
final class NickHistoryTest extends TestCase
{
    #[Test]
    public function recordCreatesHistoryEntry(): void
    {
        $performedAt = new DateTimeImmutable('2024-01-15 10:30:00');

        $history = NickHistory::record(
            nickId: 42,
            action: 'SUSPEND',
            performedBy: 'Operator1',
            performedByNickId: 10,
            message: 'Suspended for rule violation',
            extraData: ['duration' => '30d'],
            performedAt: $performedAt,
        );

        self::assertSame(0, $history->getId());
        self::assertSame(42, $history->getNickId());
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
        $history = NickHistory::record(
            nickId: 42,
            action: 'SET_PASSWORD',
            performedBy: 'UnregisteredOper',
            performedByNickId: null,
            message: 'Password changed via RECOVER',
        );

        self::assertNull($history->getPerformedByNickId());
    }

    #[Test]
    public function recordWithDefaultPerformedAt(): void
    {
        $before = new DateTimeImmutable();

        $history = NickHistory::record(
            nickId: 1,
            action: 'HISTORY_ADD',
            performedBy: 'Admin',
            performedByNickId: 5,
            message: 'Manual note',
        );

        $after = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($before, $history->getPerformedAt());
        self::assertLessThanOrEqual($after, $history->getPerformedAt());
    }

    #[Test]
    public function recordWithEmptyExtraData(): void
    {
        $history = NickHistory::record(
            nickId: 1,
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

        $history = NickHistory::record(
            nickId: 42,
            action: 'RECOVER',
            performedBy: 'User',
            performedByNickId: null,
            message: 'Password reset',
            extraData: ['method' => 'email_token'],
            performedAt: $performedAt,
        );

        $array = $history->toArray();

        self::assertSame(0, $array['id']);
        self::assertSame(42, $array['nick_id']);
        self::assertSame('RECOVER', $array['action']);
        self::assertSame('User', $array['performed_by']);
        self::assertNull($array['performed_by_nick_id']);
        self::assertSame('2024-01-15T10:30:00+00:00', $array['performed_at']);
        self::assertSame('Password reset', $array['message']);
        self::assertSame(['method' => 'email_token'], $array['extra_data']);
    }

    #[Test]
    public function setIdSetsIdForDoctrineHydration(): void
    {
        $history = NickHistory::record(
            nickId: 42,
            action: 'TEST',
            performedBy: 'Admin',
            performedByNickId: 5,
            message: 'Test message',
        );

        self::assertSame(0, $history->getId());

        $history->setId(100);

        self::assertSame(100, $history->getId());
    }
}
