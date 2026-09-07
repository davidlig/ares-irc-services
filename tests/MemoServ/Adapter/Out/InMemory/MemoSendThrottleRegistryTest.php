<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\InMemory;

use App\MemoServ\Adapter\Out\InMemory\MemoSendThrottleRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(MemoSendThrottleRegistry::class)]
final class MemoSendThrottleRegistryTest extends TestCase
{
    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenNoPreviousSend(): void
    {
        $registry = new MemoSendThrottleRegistry();

        self::assertSame(0, $registry->getRemainingCooldownSeconds('UID1', 60));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenMinIntervalZeroOrNegative(): void
    {
        $registry = new MemoSendThrottleRegistry();
        $registry->recordSend('UID1');

        self::assertSame(0, $registry->getRemainingCooldownSeconds('UID1', 0));
        self::assertSame(0, $registry->getRemainingCooldownSeconds('UID1', -1));
    }

    #[Test]
    public function recordSendAndGetLastSendAt(): void
    {
        $registry = new MemoSendThrottleRegistry();

        self::assertNull($registry->getLastSendAt('UID1'));

        $registry->recordSend('UID1');

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastSendAt('UID1'));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsPositiveWithinWindow(): void
    {
        $registry = new MemoSendThrottleRegistry();
        $registry->recordSend('UID1');

        $remaining = $registry->getRemainingCooldownSeconds('UID1', 120);

        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(120, $remaining);
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroAfterCooldownExpires(): void
    {
        $registry = new MemoSendThrottleRegistry();

        $oldDatetime = new DateTimeImmutable('-10 seconds');
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('lastSendAt');
        $property->setValue($registry, ['UID1' => $oldDatetime]);

        $remaining = $registry->getRemainingCooldownSeconds('UID1', 1);

        self::assertSame(0, $remaining);
    }
}
