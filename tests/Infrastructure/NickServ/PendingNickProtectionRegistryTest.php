<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ;

use App\Infrastructure\NickServ\PendingNickProtectionRegistry;
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

        self::assertFalse($registry->has('001ABC'));

        $registry->schedule('001ABC', 10.0);
        self::assertTrue($registry->has('001ABC'));

        $registry->cancel('001ABC');
        self::assertFalse($registry->has('001ABC'));
    }

    #[Test]
    public function flushExpiredReturnsOnlyExpiredUids(): void
    {
        $registry = new PendingNickProtectionRegistry();

        self::assertSame([], $registry->flushExpired());

        // Expired immediately (-1 second in the past)
        $registry->schedule('001EXPIRED', -1.0);
        // Not expired (10 seconds in the future)
        $registry->schedule('001FUTURE', 10.0);

        $expired = $registry->flushExpired();

        self::assertSame(['001EXPIRED'], $expired);
        self::assertFalse($registry->has('001EXPIRED'));
        self::assertTrue($registry->has('001FUTURE'));
    }

    #[Test]
    public function clearEmptiesRegistry(): void
    {
        $registry = new PendingNickProtectionRegistry();
        $registry->schedule('001ABC', 10.0);
        $registry->schedule('001DEF', 10.0);

        $registry->clear();

        self::assertFalse($registry->has('001ABC'));
        self::assertFalse($registry->has('001DEF'));
        self::assertSame([], $registry->flushExpired());
    }
}
