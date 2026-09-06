<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\InMemoryResendThrottle;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryResendThrottle::class)]
final class InMemoryResendThrottleTest extends TestCase
{
    #[Test]
    public function returnsZeroWhenNoPriorResend(): void
    {
        $registry = new PendingVerificationRegistry();
        $throttle = new InMemoryResendThrottle($registry);

        self::assertSame(0, $throttle->remainingCooldownSeconds('Nick', 300, new DateTimeImmutable()));
    }

    #[Test]
    public function returnsZeroWhenIntervalIsZeroOrNegative(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->recordResend('Nick');
        $throttle = new InMemoryResendThrottle($registry);

        self::assertSame(0, $throttle->remainingCooldownSeconds('Nick', 0, new DateTimeImmutable()));
        self::assertSame(0, $throttle->remainingCooldownSeconds('Nick', -10, new DateTimeImmutable()));
    }

    #[Test]
    public function calculatesRemainingCooldownAccurately(): void
    {
        $registry = new PendingVerificationRegistry();
        $throttle = new InMemoryResendThrottle($registry);

        $now = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
        $throttle->recordResend('Nick', $now);

        // Right after resend:
        $checkTime = new DateTimeImmutable('2026-09-06 12:01:00 UTC');
        $remaining = $throttle->remainingCooldownSeconds('Nick', 300, $checkTime);
        self::assertGreaterThanOrEqual(239, $remaining);
        self::assertLessThanOrEqual(241, $remaining);

        // After cooldown expires:
        $afterCooldown = new DateTimeImmutable('2026-09-06 12:06:00 UTC');
        self::assertSame(0, $throttle->remainingCooldownSeconds('Nick', 300, $afterCooldown));
    }
}
