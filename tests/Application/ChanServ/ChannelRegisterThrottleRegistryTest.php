<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ;

use App\Application\ChanServ\ChannelRegisterThrottleRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRegisterThrottleRegistry::class)]
final class ChannelRegisterThrottleRegistryTest extends TestCase
{
    #[Test]
    public function getLastRegistrationAtReturnsNullInitially(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();

        self::assertNull($registry->getLastRegistrationAt(1));
    }

    #[Test]
    public function recordRegistrationStoresTime(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(10);

        self::assertInstanceOf(\DateTimeImmutable::class, $registry->getLastRegistrationAt(10));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenNoLastRegistration(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();

        self::assertSame(0, $registry->getRemainingCooldownSeconds(1, 60));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenIntervalNonPositive(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(1);

        self::assertSame(0, $registry->getRemainingCooldownSeconds(1, 0));
        self::assertSame(0, $registry->getRemainingCooldownSeconds(1, -1));
    }

    #[Test]
    public function pruneExpiredCooldownsRemovesExpiredEntries(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(1);
        $registry->recordRegistration(2);

        $removed = $registry->pruneExpiredCooldowns(1);
        self::assertGreaterThanOrEqual(0, $removed);
    }

    #[Test]
    public function pruneExpiredCooldownsReturnsZeroWhenIntervalNonPositive(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(1);

        self::assertSame(0, $registry->pruneExpiredCooldowns(0));
    }
}
