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
}
