<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ;

use App\Application\MemoServ\MemoServSendThrottleRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoServSendThrottleRegistry::class)]
final class MemoServSendThrottleRegistryTest extends TestCase
{
    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenNoPreviousSend(): void
    {
        $registry = new MemoServSendThrottleRegistry();

        self::assertSame(0, $registry->getRemainingCooldownSeconds('UID1', 60));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenMinIntervalZero(): void
    {
        $registry = new MemoServSendThrottleRegistry();
        $registry->recordSend('UID1');

        self::assertSame(0, $registry->getRemainingCooldownSeconds('UID1', 0));
    }

    #[Test]
    public function recordSendAndGetLastSendAt(): void
    {
        $registry = new MemoServSendThrottleRegistry();

        self::assertNull($registry->getLastSendAt('UID1'));

        $registry->recordSend('UID1');

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastSendAt('UID1'));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsPositiveWithinWindow(): void
    {
        $registry = new MemoServSendThrottleRegistry();
        $registry->recordSend('UID1');

        $remaining = $registry->getRemainingCooldownSeconds('UID1', 120);

        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(120, $remaining);
    }
}
