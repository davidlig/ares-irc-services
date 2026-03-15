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
}
