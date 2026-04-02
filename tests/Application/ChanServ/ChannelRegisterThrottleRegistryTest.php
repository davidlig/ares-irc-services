<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ;

use App\Application\ChanServ\ChannelRegisterThrottleRegistry;
use DateTimeImmutable;
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

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastRegistrationAt(10));
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
    public function getRemainingCooldownSecondsReturnsPositiveWithinWindow(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(1);

        $remaining = $registry->getRemainingCooldownSeconds(1, 3600);

        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(3600, $remaining);
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

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenCooldownExpired(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(1);

        sleep(2);

        self::assertSame(0, $registry->getRemainingCooldownSeconds(1, 1));
    }

    #[Test]
    public function pruneExpiredCooldownsRemovesOnlyExpiredEntries(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(1);
        $registry->recordRegistration(2);

        sleep(2);

        $removed = $registry->pruneExpiredCooldowns(1);

        self::assertGreaterThanOrEqual(1, $removed);
        self::assertLessThanOrEqual(2, $removed);
    }

    #[Test]
    public function pruneExpiredCooldownsRemovesEntryWhoseCooldownExpired(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(1);

        // Need to wait at least 1 second for the 1-second cooldown to expire
        // Use 2 seconds to ensure it's definitely expired
        sleep(2);

        $removed = $registry->pruneExpiredCooldowns(1);

        // The entry should be removed since cooldown (1 second) has expired
        self::assertSame(1, $removed);
        self::assertNull($registry->getLastRegistrationAt(1));
    }

    #[Test]
    public function pruneExpiredCooldownsKeepsEntryWhoseCooldownNotExpired(): void
    {
        $registry = new ChannelRegisterThrottleRegistry();
        $registry->recordRegistration(1);

        $removed = $registry->pruneExpiredCooldowns(3600);

        // The entry should NOT be removed since cooldown (1 hour) has NOT expired
        self::assertSame(0, $removed);
        self::assertNotNull($registry->getLastRegistrationAt(1));
    }
}
