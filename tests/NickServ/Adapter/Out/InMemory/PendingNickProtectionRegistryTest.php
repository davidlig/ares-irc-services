<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\PendingNickProtectionRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingNickProtectionRegistry::class)]
final class PendingNickProtectionRegistryTest extends TestCase
{
    #[Test]
    public function scheduleAndHasWorkAsExpected(): void
    {
        $registry = new PendingNickProtectionRegistry();
        $now = new DateTimeImmutable('2026-09-06 12:00:00.000000 UTC');

        self::assertFalse($registry->has('001ABC'));

        $registry->schedule('001ABC', $now, 10.0);
        self::assertTrue($registry->has('001ABC'));

        $registry->cancel('001ABC');
        self::assertFalse($registry->has('001ABC'));
    }

    #[Test]
    public function flushExpiredReturnsOnlyExpiredUids(): void
    {
        $registry = new PendingNickProtectionRegistry();
        $now = new DateTimeImmutable('2026-09-06 12:00:00.000000 UTC');

        self::assertSame([], $registry->flushExpired($now));

        // Expired immediately (-1 second in the past)
        $registry->schedule('001EXPIRED', $now, -1.0);
        // Not expired (10 seconds in the future)
        $registry->schedule('001FUTURE', $now, 10.0);

        $expired = $registry->flushExpired($now);

        self::assertSame(['001EXPIRED'], $expired);
        self::assertFalse($registry->has('001EXPIRED'));
        self::assertTrue($registry->has('001FUTURE'));
    }

    #[Test]
    public function clearEmptiesRegistry(): void
    {
        $registry = new PendingNickProtectionRegistry();
        $now = new DateTimeImmutable('2026-09-06 12:00:00.000000 UTC');
        $registry->schedule('001ABC', $now, 10.0);
        $registry->schedule('001DEF', $now, 10.0);

        $registry->clear();

        self::assertFalse($registry->has('001ABC'));
        self::assertFalse($registry->has('001DEF'));
        self::assertSame([], $registry->flushExpired($now));
    }
}
