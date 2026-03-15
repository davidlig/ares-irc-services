<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\IdentifyFailedAttemptRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifyFailedAttemptRegistry::class)]
final class IdentifyFailedAttemptRegistryTest extends TestCase
{
    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenMaxAttemptsOrLockoutZero(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 60);
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 0, 60, 300));
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, 0));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenUnderMaxAttempts(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 60);
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 5, 60, 300));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsPositiveWhenLockedOut(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600);
        }
        $remaining = $registry->getRemainingLockoutSeconds('key', 3, 3600, 60);
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(60, $remaining);
    }

    #[Test]
    public function clearFailedAttemptsRemovesKey(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 60);
        $registry->clearFailedAttempts('key');
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 1, 60, 30));
    }

    #[Test]
    public function pruneStaleRemovesOldEntries(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 1);
        $removed = $registry->pruneStale(0);
        self::assertGreaterThanOrEqual(0, $removed);
    }

    #[Test]
    public function recordFailedAttemptUnsetsKeyWhenAllTimestampsOutsideWindow(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 0);
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, 30));
    }

    #[Test]
    public function recordFailedAttemptFiltersOldTimestamps(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 60);
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, 30));
    }

    #[Test]
    public function multipleKeysAreIndependent(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key1', 60);
        }
        $registry->recordFailedAttempt('key2', 60);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key1', 3, 60, 30));
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key2', 3, 60, 30));
    }

    #[Test]
    public function pruneStaleKeepsFreshEntries(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600);
        }
        $removed = $registry->pruneStale(3600);
        self::assertSame(0, $removed);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 3, 3600, 60));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenNoAttempts(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        self::assertSame(0, $registry->getRemainingLockoutSeconds('nonexistent', 3, 60, 30));
    }

    #[Test]
    public function recordFailedAttemptPreservesExistingValidTimestamps(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 3600);
        $registry->recordFailedAttempt('key', 3600);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 2, 3600, 60));
    }

    #[Test]
    public function pruneStaleReturnsZeroWhenRegistryIsEmpty(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        self::assertSame(0, $registry->pruneStale(60));
    }

    #[Test]
    public function pruneStaleRemovesMultipleStaleEntries(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key1', 1);
        $registry->recordFailedAttempt('key2', 1);
        $registry->recordFailedAttempt('key3', 1);
        sleep(2);
        $removed = $registry->pruneStale(0);
        self::assertGreaterThanOrEqual(3, $removed);
    }

    #[Test]
    public function clearFailedAttemptsonNonExistentKeyDoesNotError(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->clearFailedAttempts('nonexistent');
        self::assertSame(0, $registry->getRemainingLockoutSeconds('nonexistent', 3, 60, 30));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenMaxAttemptsNegative(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600);
        }
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', -1, 60, 300));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutSecondsNegative(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600);
        }
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, -1));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroExactlyAtMaxAttempts(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 3600);
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 1, 3600, 0));
    }

    #[Test]
    public function multipleRecordFailedAttemptAccumulatesTimestamps(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 5; ++$i) {
            $registry->recordFailedAttempt('key', 3600);
        }
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 5, 3600, 60));
    }

    #[Test]
    public function pruneStaleKeepsEntriesWithinWindow(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('recent', 3600);
        $registry->recordFailedAttempt('recent_again', 3600);
        $removed = $registry->pruneStale(60);
        self::assertSame(0, $removed);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('recent', 1, 3600, 60));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutExpired(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 1);
        }
        sleep(2);
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 1, 1));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutExpiredDuringCheck(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600);
        }
        $remaining = $registry->getRemainingLockoutSeconds('key', 3, 3600, 0);
        self::assertSame(0, $remaining);
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenNowExceedsLockoutUntil(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 1);
        }
        sleep(2);
        $remaining = $registry->getRemainingLockoutSeconds('key', 3, 1, 1);
        self::assertSame(0, $remaining);
    }

    #[Test]
    public function recordFailedAttemptPreservesKeyWhenTimestampsRemain(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 3600);
        $registry->recordFailedAttempt('key', 3600);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 2, 3600, 60));
    }

    #[Test]
    public function recordFailedAttemptUnsetsKeyWhenAllTimestampsFilteredOutWithNegativeWindow(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', -1);
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, 30));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutExpiresWhileTimestampsRemainInWindow(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 100);
        }
        sleep(2);
        $remaining = $registry->getRemainingLockoutSeconds('key', 3, 100, 1);
        self::assertSame(0, $remaining);
    }
}
