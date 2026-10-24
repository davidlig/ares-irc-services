<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\InMemoryRescueAttemptLimiter;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryRescueAttemptLimiter::class)]
final class InMemoryRescueAttemptLimiterTest extends TestCase
{
    #[Test]
    public function accountAndClientAggregationAndExpiration(): void
    {
        $limiter = new InMemoryRescueAttemptLimiter();
        $now = new DateTimeImmutable('2026-01-01');
        self::assertSame(0, $limiter->remainingCooldownSeconds(1, 'a', $now));
        for ($i = 0; $i < 5; ++$i) {
            $limiter->recordFailure(1, 'a', $now);
        }
        $limiter->clearFailures(1, 'a');
        self::assertSame(600, $limiter->remainingCooldownSeconds(1, 'b', $now));
        self::assertSame(600, $limiter->remainingCooldownSeconds(2, 'a', $now));
        self::assertSame(0, $limiter->remainingCooldownSeconds(2, 'b', $now));
        $limiter->recordFailure(1, 'a', $now->modify('+1 second'));
        self::assertSame(599, $limiter->remainingCooldownSeconds(1, 'a', $now->modify('+1 second')));
        self::assertSame(0, $limiter->remainingCooldownSeconds(1, 'a', $now->modify('+600 seconds')));
        self::assertSame(2, $limiter->prune($now->modify('+901 seconds')));
        self::assertSame(0, $limiter->prune($now->modify('+902 seconds')));
    }

    #[Test]
    public function oldFailuresDoNotAccumulateAndCapacityFailsClosed(): void
    {
        $limiter = new InMemoryRescueAttemptLimiter(2, 10, 5, 2);
        $now = new DateTimeImmutable('2026-01-01');
        $limiter->recordFailure(1, 'a', $now);
        $limiter->recordFailure(1, 'a', $now->modify('+11 seconds'));
        self::assertSame(0, $limiter->remainingCooldownSeconds(1, 'a', $now->modify('+11 seconds')));
        $limiter->recordFailure(2, 'b', $now->modify('+11 seconds'));
        self::assertSame(5, $limiter->remainingCooldownSeconds(99, 'z', $now->modify('+11 seconds')));
        self::assertSame(0, $limiter->remainingCooldownSeconds(99, 'z', $now->modify('+16 seconds')));
    }

    #[Test]
    public function invalidConfigurationIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InMemoryRescueAttemptLimiter(0);
    }
}
