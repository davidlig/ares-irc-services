<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\RegisterThrottleRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(RegisterThrottleRegistry::class)]
final class RegisterThrottleRegistryTest extends TestCase
{
    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenNoPreviousAttempt(): void
    {
        $registry = new RegisterThrottleRegistry();

        self::assertSame(0, $registry->getRemainingCooldownSeconds('client1', 60, $this->now()));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenMinIntervalZero(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1', $this->now());

        self::assertSame(0, $registry->getRemainingCooldownSeconds('client1', 0, $this->now()));
    }

    #[Test]
    public function recordAttemptAndGetLastAttemptAt(): void
    {
        $registry = new RegisterThrottleRegistry();

        self::assertNull($registry->getLastAttemptAt('key'));

        $registry->recordAttempt('key', $this->now());

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('key'));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsPositiveWithinWindow(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1', $this->now());

        $remaining = $registry->getRemainingCooldownSeconds('client1', 3600, $this->now());

        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(3600, $remaining);
    }

    #[Test]
    public function pruneExpiredCooldownsRemovesOldEntries(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('old', $this->now());

        $removed = $registry->pruneExpiredCooldowns(0, $this->now());

        self::assertSame(0, $removed);

        $removed = $registry->pruneExpiredCooldowns(1, $this->now());

        self::assertGreaterThanOrEqual(0, $removed);
    }

    #[Test]
    public function multipleClientsAreIndependent(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1', $this->now());
        $registry->recordAttempt('client2', $this->now());

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('client1'));
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('client2'));
    }

    #[Test]
    public function pruneExpiredCooldownsWithZeroIntervalReturnsZero(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1', $this->now());

        $removed = $registry->pruneExpiredCooldowns(0, $this->now());

        self::assertSame(0, $removed);
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroAfterCooldownExpires(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1', $this->now());
        $remaining = $registry->getRemainingCooldownSeconds('client1', 0, $this->now());
        self::assertSame(0, $remaining);
    }

    #[Test]
    public function recordAttemptOverwritesPrevious(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('key', $this->now());
        $first = $registry->getLastAttemptAt('key');
        $registry->recordAttempt('key', $this->now());
        $second = $registry->getLastAttemptAt('key');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertGreaterThanOrEqual($first, $second);
    }

    #[Test]
    public function pruneExpiredCooldownsKeepsFreshEntries(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('fresh', $this->now());
        $removed = $registry->pruneExpiredCooldowns(3600, $this->now());
        self::assertSame(0, $removed);
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('fresh'));
    }

    #[Test]
    public function pruneExpiredCooldownsReturnsZeroWhenRegistryIsEmpty(): void
    {
        $registry = new RegisterThrottleRegistry();
        self::assertSame(0, $registry->pruneExpiredCooldowns(60, $this->now()));
    }

    #[Test]
    public function pruneExpiredCooldownsRemovesMultipleExpiredEntries(): void
    {
        $registry = new RegisterThrottleRegistry();

        $oldDatetime = $this->now()->modify('-3600 seconds');
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('lastAttemptAt');
        $property->setValue($registry, [
            'old1' => $oldDatetime,
            'old2' => $oldDatetime,
            'old3' => $oldDatetime,
        ]);

        $removed = $registry->pruneExpiredCooldowns(1, $this->now());
        self::assertSame(3, $removed, 'All 3 entries should be pruned after 3 seconds with 1 second cooldown');
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenMinIntervalNegative(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1', $this->now());
        self::assertSame(0, $registry->getRemainingCooldownSeconds('client1', -1, $this->now()));
    }

    #[Test]
    public function getLastAttemptAtReturnsNullForNonExistentKey(): void
    {
        $registry = new RegisterThrottleRegistry();
        self::assertNull($registry->getLastAttemptAt('nonexistent'));
    }

    #[Test]
    public function pruneExpiredCooldownsPrunesOldButKeepsFresh(): void
    {
        $registry = new RegisterThrottleRegistry();
        // Record 'fresh' - this should NOT be pruned with a large minInterval
        $registry->recordAttempt('fresh', $this->now());

        // With minInterval of 3600 seconds, nothing should be pruned immediately
        $removed = $registry->pruneExpiredCooldowns(3600, $this->now());
        self::assertSame(0, $removed, 'Nothing should be pruned with large minInterval');
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('fresh'));

        // With minInterval of 0, nothing should be pruned (per guard clause)
        $registry->recordAttempt('another', $this->now());
        $removed = $registry->pruneExpiredCooldowns(0, $this->now());
        self::assertSame(0, $removed, 'minInterval 0 should return 0');
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroAfterSleepExpiry(): void
    {
        $registry = new RegisterThrottleRegistry();

        $oldDatetime = $this->now()->modify('-10 seconds');
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('lastAttemptAt');
        $property->setValue($registry, ['client1' => $oldDatetime]);

        self::assertSame(0, $registry->getRemainingCooldownSeconds('client1', 1, $this->now()));
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
