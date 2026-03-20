<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\RegisterThrottleRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegisterThrottleRegistry::class)]
final class RegisterThrottleRegistryTest extends TestCase
{
    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenNoPreviousAttempt(): void
    {
        $registry = new RegisterThrottleRegistry();

        self::assertSame(0, $registry->getRemainingCooldownSeconds('client1', 60));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenMinIntervalZero(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1');

        self::assertSame(0, $registry->getRemainingCooldownSeconds('client1', 0));
    }

    #[Test]
    public function recordAttemptAndGetLastAttemptAt(): void
    {
        $registry = new RegisterThrottleRegistry();

        self::assertNull($registry->getLastAttemptAt('key'));

        $registry->recordAttempt('key');

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('key'));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsPositiveWithinWindow(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1');

        $remaining = $registry->getRemainingCooldownSeconds('client1', 3600);

        self::assertGreaterThan(0, $remaining);
        self::assertLessThanOrEqual(3600, $remaining);
    }

    #[Test]
    public function pruneExpiredCooldownsRemovesOldEntries(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('old');

        $removed = $registry->pruneExpiredCooldowns(0);

        self::assertSame(0, $removed);

        $removed = $registry->pruneExpiredCooldowns(1);

        self::assertGreaterThanOrEqual(0, $removed);
    }

    #[Test]
    public function multipleClientsAreIndependent(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1');
        $registry->recordAttempt('client2');

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('client1'));
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('client2'));
    }

    #[Test]
    public function pruneExpiredCooldownsWithZeroIntervalReturnsZero(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1');

        $removed = $registry->pruneExpiredCooldowns(0);

        self::assertSame(0, $removed);
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroAfterCooldownExpires(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1');
        $remaining = $registry->getRemainingCooldownSeconds('client1', 0);
        self::assertSame(0, $remaining);
    }

    #[Test]
    public function recordAttemptOverwritesPrevious(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('key');
        $first = $registry->getLastAttemptAt('key');
        usleep(10000);
        $registry->recordAttempt('key');
        $second = $registry->getLastAttemptAt('key');
        self::assertGreaterThanOrEqual($first, $second);
    }

    #[Test]
    public function pruneExpiredCooldownsKeepsFreshEntries(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('fresh');
        $removed = $registry->pruneExpiredCooldowns(3600);
        self::assertSame(0, $removed);
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastAttemptAt('fresh'));
    }

    #[Test]
    public function pruneExpiredCooldownsReturnsZeroWhenRegistryIsEmpty(): void
    {
        $registry = new RegisterThrottleRegistry();
        self::assertSame(0, $registry->pruneExpiredCooldowns(60));
    }

    #[Test]
    public function pruneExpiredCooldownsRemovesMultipleExpiredEntries(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('old1');
        $registry->recordAttempt('old2');
        $registry->recordAttempt('old3');
        sleep(2);
        $removed = $registry->pruneExpiredCooldowns(1);
        self::assertGreaterThanOrEqual(3, $removed);
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroWhenMinIntervalNegative(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1');
        self::assertSame(0, $registry->getRemainingCooldownSeconds('client1', -1));
    }

    #[Test]
    public function getLastAttemptAtReturnsNullForNonExistentKey(): void
    {
        $registry = new RegisterThrottleRegistry();
        self::assertNull($registry->getLastAttemptAt('nonexistent'));
    }

    #[Test]
    public function pruneExpiredCooldownsPrunesMixedEntries(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('old');
        sleep(1);
        $registry->recordAttempt('fresh');
        sleep(3);
        $removed = $registry->pruneExpiredCooldowns(2);
        self::assertSame(2, $removed, 'Both entries should be pruned after 3+ seconds');
        self::assertNull($registry->getLastAttemptAt('old'));
        self::assertNull($registry->getLastAttemptAt('fresh'));
    }

    #[Test]
    public function getRemainingCooldownSecondsReturnsZeroAfterSleepExpiry(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('client1');
        sleep(2);
        self::assertSame(0, $registry->getRemainingCooldownSeconds('client1', 1));
    }
}
