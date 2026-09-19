<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\IdentifyFailedAttemptRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(IdentifyFailedAttemptRegistry::class)]
final class IdentifyFailedAttemptRegistryTest extends TestCase
{
    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenMaxAttemptsOrLockoutZero(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 60, $this->now());
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 0, 60, 300, $this->now()));
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, 0, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenUnderMaxAttempts(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 60, $this->now());
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 5, 60, 300, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsPositiveWhenLockedOut(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600, $this->now());
        }
        $remaining = $registry->getRemainingLockoutSeconds('key', 3, 3600, 60, $this->now());
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(60, $remaining);
    }

    #[Test]
    public function clearFailedAttemptsRemovesKey(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 60, $this->now());
        $registry->clearFailedAttempts('key');
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 1, 60, 30, $this->now()));
    }

    #[Test]
    public function pruneStaleRemovesOldEntries(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 1, $this->now());
        $removed = $registry->pruneStale(0, $this->now());
        self::assertGreaterThanOrEqual(0, $removed);
    }

    #[Test]
    public function recordFailedAttemptUnsetsKeyWhenAllTimestampsOutsideWindow(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 0, $this->now());
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, 30, $this->now()));
    }

    #[Test]
    public function recordFailedAttemptFiltersOldTimestamps(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 60, $this->now());
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, 30, $this->now()));
    }

    #[Test]
    public function multipleKeysAreIndependent(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key1', 60, $this->now());
        }
        $registry->recordFailedAttempt('key2', 60, $this->now());
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key1', 3, 60, 30, $this->now()));
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key2', 3, 60, 30, $this->now()));
    }

    #[Test]
    public function pruneStaleKeepsFreshEntries(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600, $this->now());
        }
        $removed = $registry->pruneStale(3600, $this->now());
        self::assertSame(0, $removed);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 3, 3600, 60, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenNoAttempts(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        self::assertSame(0, $registry->getRemainingLockoutSeconds('nonexistent', 3, 60, 30, $this->now()));
    }

    #[Test]
    public function recordFailedAttemptPreservesExistingValidTimestamps(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 3600, $this->now());
        $registry->recordFailedAttempt('key', 3600, $this->now());
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 2, 3600, 60, $this->now()));
    }

    #[Test]
    public function pruneStaleReturnsZeroWhenRegistryIsEmpty(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        self::assertSame(0, $registry->pruneStale(60, $this->now()));
    }

    #[Test]
    public function pruneStaleRemovesMultipleStaleEntries(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();

        $oldTimestamp = $this->now()->getTimestamp() - 3600;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('failuresByKey');
        $property->setValue($registry, [
            'key1' => [$oldTimestamp],
            'key2' => [$oldTimestamp],
            'key3' => [$oldTimestamp],
        ]);

        $removed = $registry->pruneStale(0, $this->now());
        self::assertSame(3, $removed);
    }

    #[Test]
    public function clearFailedAttemptsonNonExistentKeyDoesNotError(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->clearFailedAttempts('nonexistent');
        self::assertSame(0, $registry->getRemainingLockoutSeconds('nonexistent', 3, 60, 30, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenMaxAttemptsNegative(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600, $this->now());
        }
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', -1, 60, 300, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutSecondsNegative(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600, $this->now());
        }
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, -1, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroExactlyAtMaxAttempts(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 3600, $this->now());
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 1, 3600, 0, $this->now()));
    }

    #[Test]
    public function multipleRecordFailedAttemptAccumulatesTimestamps(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 5; ++$i) {
            $registry->recordFailedAttempt('key', 3600, $this->now());
        }
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 5, 3600, 60, $this->now()));
    }

    #[Test]
    public function pruneStaleKeepsEntriesWithinWindow(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('recent', 3600, $this->now());
        $registry->recordFailedAttempt('recent_again', 3600, $this->now());
        $removed = $registry->pruneStale(60, $this->now());
        self::assertSame(0, $removed);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('recent', 1, 3600, 60, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutExpired(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();

        $oldTimestamp = $this->now()->getTimestamp() - 10;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('failuresByKey');
        $property->setValue($registry, ['key' => [$oldTimestamp, $oldTimestamp, $oldTimestamp]]);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 1, 1, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutExpiredDuringCheck(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordFailedAttempt('key', 3600, $this->now());
        }
        $remaining = $registry->getRemainingLockoutSeconds('key', 3, 3600, 0, $this->now());
        self::assertSame(0, $remaining);
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenNowExceedsLockoutUntil(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();

        $oldTimestamp = $this->now()->getTimestamp() - 10;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('failuresByKey');
        $property->setValue($registry, ['key' => [$oldTimestamp, $oldTimestamp, $oldTimestamp]]);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 1, 1, $this->now()));
    }

    #[Test]
    public function recordFailedAttemptPreservesKeyWhenTimestampsRemain(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', 3600, $this->now());
        $registry->recordFailedAttempt('key', 3600, $this->now());
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 2, 3600, 60, $this->now()));
    }

    #[Test]
    public function recordFailedAttemptUnsetsKeyWhenAllTimestampsFilteredOutWithNegativeWindow(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $registry->recordFailedAttempt('key', -1, $this->now());
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 60, 30, $this->now()));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutExpiresWhileTimestampsRemainInWindow(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();

        $oldTimestamp = $this->now()->getTimestamp() - 10;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('failuresByKey');
        $property->setValue($registry, ['key' => [$oldTimestamp, $oldTimestamp, $oldTimestamp]]);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key', 3, 100, 1, $this->now()));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
