<?php

declare(strict_types=1);

namespace App\Tests\Application\Services\Antiflood;

use App\Application\Services\Antiflood\AntifloodRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AntifloodRegistry::class)]
final class AntifloodRegistryTest extends TestCase
{
    #[Test]
    public function recordCommandAddsTimestamp(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);

        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key1', 3, 3600, 60));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenMaxMessagesZero(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 0, 60, 60));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutSecondsZero(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 2, 3600, 0));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenMaxMessagesNegative(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', -1, 60, 60));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenNoRecord(): void
    {
        $registry = new AntifloodRegistry();

        self::assertSame(0, $registry->getRemainingLockoutSeconds('nonexistent', 5, 10, 60));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenUnderLimit(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 5, 3600, 60));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsPositiveWhenAtLimit(): void
    {
        $registry = new AntifloodRegistry();
        for ($i = 0; $i < 5; ++$i) {
            $registry->recordCommand('key1', 3600);
        }

        $remaining = $registry->getRemainingLockoutSeconds('key1', 5, 3600, 60);
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(60, $remaining);
    }

    #[Test]
    public function multipleKeysAreIndependent(): void
    {
        $registry = new AntifloodRegistry();
        for ($i = 0; $i < 5; ++$i) {
            $registry->recordCommand('key1', 3600);
        }
        $registry->recordCommand('key2', 3600);

        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key1', 5, 3600, 60));
        self::assertSame(0, $registry->getRemainingLockoutSeconds('key2', 5, 3600, 60));
    }

    #[Test]
    public function recordCommandPrunesTimestampsOutsideWindow(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 100;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setAccessible(true);
        $property->setValue($registry, ['key1' => [$oldTimestamp]]);

        $registry->recordCommand('key1', 10);

        $remaining = $registry->getRemainingLockoutSeconds('key1', 2, 10, 60);
        self::assertSame(0, $remaining);
    }

    #[Test]
    public function recordCommandUnsetsKeyWhenAllTimestampsFilteredOut(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 0);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 3, 60, 30));
    }

    #[Test]
    public function recordCommandUnsetsKeyWhenAllTimestampsFilteredOutWithReflection(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 3600);

        $registry->recordCommand('key1', -1);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 3, 60, 30));
    }

    #[Test]
    public function pruneStaleRemovesOldEntries(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 3600;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setAccessible(true);
        $property->setValue($registry, [
            'key1' => [$oldTimestamp],
            'key2' => [$oldTimestamp],
            'key3' => [$oldTimestamp],
        ]);

        $removed = $registry->pruneStale(0);
        self::assertSame(3, $removed);
    }

    #[Test]
    public function pruneStaleKeepsFreshEntries(): void
    {
        $registry = new AntifloodRegistry();
        for ($i = 0; $i < 3; ++$i) {
            $registry->recordCommand('key', 3600);
        }
        $removed = $registry->pruneStale(3600);
        self::assertSame(0, $removed);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key', 3, 3600, 60));
    }

    #[Test]
    public function pruneStaleReturnsZeroWhenEmpty(): void
    {
        $registry = new AntifloodRegistry();

        self::assertSame(0, $registry->pruneStale(60));
    }

    #[Test]
    public function atExactLimitTriggersLockout(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 3600);

        $remaining = $registry->getRemainingLockoutSeconds('key1', 1, 3600, 60);
        self::assertGreaterThan(0, $remaining);
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutExpired(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 10;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setAccessible(true);
        $property->setValue($registry, ['key1' => [$oldTimestamp, $oldTimestamp, $oldTimestamp]]);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 3, 1, 1));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsRemainingTime(): void
    {
        $registry = new AntifloodRegistry();

        for ($i = 0; $i < 3; ++$i) {
            $registry->recordCommand('key1', 3600);
        }

        $remaining = $registry->getRemainingLockoutSeconds('key1', 3, 3600, 120);
        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(120, $remaining);
    }

    #[Test]
    public function slidingWindowOnlyCountsRecentCommands(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 100;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setAccessible(true);
        $property->setValue($registry, ['key1' => [$oldTimestamp, $oldTimestamp, $oldTimestamp]]);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 3, 10, 60));
    }

    #[Test]
    public function lockoutPersistsAfterWindowExpires(): void
    {
        $registry = new AntifloodRegistry();

        for ($i = 0; $i < 3; ++$i) {
            $registry->recordCommand('key1', 3600);
        }

        $remaining = $registry->getRemainingLockoutSeconds('key1', 3, 3600, 60);
        self::assertGreaterThan(0, $remaining);

        $remaining2 = $registry->getRemainingLockoutSeconds('key1', 3, 1, 60);
        self::assertGreaterThan(0, $remaining2);
    }

    #[Test]
    public function lockoutClearsAfterCooldownExpires(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 100;
        $reflection = new ReflectionClass($registry);
        $lockoutProperty = $reflection->getProperty('lockoutUntilByKey');
        $lockoutProperty->setAccessible(true);
        $lockoutProperty->setValue($registry, ['key1' => $oldTimestamp + 1]);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 3, 1, 60));
    }

    #[Test]
    public function repeatedLockoutChecksReturnConsistentRemaining(): void
    {
        $registry = new AntifloodRegistry();

        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);

        $remaining1 = $registry->getRemainingLockoutSeconds('key1', 3, 3600, 60);
        self::assertGreaterThan(0, $remaining1);

        $remaining2 = $registry->getRemainingLockoutSeconds('key1', 3, 3600, 60);
        self::assertLessThanOrEqual($remaining1, $remaining2);
    }

    #[Test]
    public function lockoutIsAbsoluteNotSliding(): void
    {
        $registry = new AntifloodRegistry();

        for ($i = 0; $i < 3; ++$i) {
            $registry->recordCommand('key1', 3600);
        }

        $remaining = $registry->getRemainingLockoutSeconds('key1', 3, 3600, 60);
        self::assertGreaterThan(0, $remaining);

        $remainingWithShortWindow = $registry->getRemainingLockoutSeconds('key1', 3, 1, 60);
        self::assertGreaterThan(0, $remainingWithShortWindow);
    }

    #[Test]
    public function recordCommandPreservesExistingValidTimestamps(): void
    {
        $registry = new AntifloodRegistry();
        $registry->recordCommand('key1', 3600);
        $registry->recordCommand('key1', 3600);
        self::assertGreaterThan(0, $registry->getRemainingLockoutSeconds('key1', 2, 3600, 60));
    }

    #[Test]
    public function pruneStaleRemovesMultipleStaleEntries(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 3600;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setAccessible(true);
        $property->setValue($registry, [
            'key1' => [$oldTimestamp],
            'key2' => [$oldTimestamp],
            'key3' => [$oldTimestamp],
        ]);

        $removed = $registry->pruneStale(0);
        self::assertSame(3, $removed);
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroForNonexistentKey(): void
    {
        $registry = new AntifloodRegistry();

        self::assertSame(0, $registry->getRemainingLockoutSeconds('nonexistent', 3, 60, 30));
    }

    #[Test]
    public function getRemainingLockoutSecondsReturnsZeroWhenLockoutExpiresWhileTimestampsRemainInWindow(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 100;
        $reflection = new ReflectionClass($registry);
        $lockoutProperty = $reflection->getProperty('lockoutUntilByKey');
        $lockoutProperty->setAccessible(true);
        $lockoutProperty->setValue($registry, ['key1' => $oldTimestamp + 1]);

        self::assertSame(0, $registry->getRemainingLockoutSeconds('key1', 3, 100, 1));
    }

    #[Test]
    public function pruneStaleKeepsKeysWithActiveLockoutEvenWithOldTimestamps(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 3600;
        $reflection = new ReflectionClass($registry);
        $timestampsProperty = $reflection->getProperty('timestampsByKey');
        $timestampsProperty->setAccessible(true);
        $timestampsProperty->setValue($registry, ['key1' => [$oldTimestamp]]);

        $lockoutProperty = $reflection->getProperty('lockoutUntilByKey');
        $lockoutProperty->setAccessible(true);
        $lockoutProperty->setValue($registry, ['key1' => time() + 60]);

        $removed = $registry->pruneStale(60);
        self::assertSame(0, $removed);
    }

    #[Test]
    public function pruneStaleRemovesExpiredLockoutWithNoTimestamps(): void
    {
        $registry = new AntifloodRegistry();

        $reflection = new ReflectionClass($registry);
        $lockoutProperty = $reflection->getProperty('lockoutUntilByKey');
        $lockoutProperty->setAccessible(true);
        $lockoutProperty->setValue($registry, ['key1' => time() - 60]);

        $removed = $registry->pruneStale(60);
        self::assertSame(1, $removed);
    }

    #[Test]
    public function markNotifiedSetsNotifiedFlag(): void
    {
        $registry = new AntifloodRegistry();
        $registry->markNotified('key1');

        self::assertTrue($registry->isNotified('key1'));
    }

    #[Test]
    public function isNotifiedReturnsFalseForUnknownKey(): void
    {
        $registry = new AntifloodRegistry();

        self::assertFalse($registry->isNotified('unknown'));
    }

    #[Test]
    public function clearNotifiedForRemovesNotifiedFlag(): void
    {
        $registry = new AntifloodRegistry();
        $registry->markNotified('key1');
        self::assertTrue($registry->isNotified('key1'));

        $registry->clearNotifiedFor('key1');
        self::assertFalse($registry->isNotified('key1'));
    }

    #[Test]
    public function lockoutExpiryClearsNotifiedFlag(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 100;
        $reflection = new ReflectionClass($registry);
        $lockoutProperty = $reflection->getProperty('lockoutUntilByKey');
        $lockoutProperty->setAccessible(true);
        $lockoutProperty->setValue($registry, ['key1' => $oldTimestamp + 1]);

        $registry->markNotified('key1');
        self::assertTrue($registry->isNotified('key1'));

        $registry->getRemainingLockoutSeconds('key1', 3, 1, 60);

        self::assertFalse($registry->isNotified('key1'));
    }

    #[Test]
    public function pruneStaleClearsNotifiedForStaleKey(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 3600;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setAccessible(true);
        $property->setValue($registry, ['key1' => [$oldTimestamp]]);

        $registry->markNotified('key1');
        self::assertTrue($registry->isNotified('key1'));

        $removed = $registry->pruneStale(0);
        self::assertSame(1, $removed);
        self::assertFalse($registry->isNotified('key1'));
    }

    #[Test]
    public function pruneStaleClearsNotifiedForExpiredLockoutWithNoTimestamps(): void
    {
        $registry = new AntifloodRegistry();

        $reflection = new ReflectionClass($registry);
        $lockoutProperty = $reflection->getProperty('lockoutUntilByKey');
        $lockoutProperty->setAccessible(true);
        $lockoutProperty->setValue($registry, ['key1' => time() - 60]);

        $registry->markNotified('key1');
        self::assertTrue($registry->isNotified('key1'));

        $removed = $registry->pruneStale(60);
        self::assertSame(1, $removed);
        self::assertFalse($registry->isNotified('key1'));
    }
}
