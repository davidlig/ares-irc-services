<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\InMemoryTemporaryRescueTokenStore;
use DateTimeImmutable;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryTemporaryRescueTokenStore::class)]
final class InMemoryTemporaryRescueTokenStoreTest extends TestCase
{
    public function testLatestChallengeExpirationAndConditionalRemoval(): void
    {
        $store = new InMemoryTemporaryRescueTokenStore();
        $now = new DateTimeImmutable('2026-01-01');
        self::assertFalse($store->matches(1, 'a', $now));
        $store->replace(1, 'a', $now->modify('+1 hour'));
        self::assertTrue($store->matches(1, 'a', $now));
        $store->replace(1, 'b', $now->modify('+1 hour'));
        $store->removeIfMatches(1, 'a');
        self::assertFalse($store->matches(1, 'a', $now));
        self::assertTrue($store->matches(1, 'b', $now));
        self::assertFalse($store->matches(1, 'b', $now->modify('+1 hour')));
        self::assertSame(0, $store->prune($now));
        self::assertSame(1, $store->prune($now->modify('+1 hour')));
        $store->removeIfMatches(1, 'b');
        $store->replace(1, 'c', $now->modify('+1 hour'));
        $store->removeIfMatches(1, 'c');
        self::assertFalse($store->matches(1, 'c', $now));
    }

    public function testConfirmedMailRecoveryDiscardsOlderOptionalChallenges(): void
    {
        $store = new InMemoryTemporaryRescueTokenStore();
        $now = new DateTimeImmutable('2026-01-01');
        $store->replace(1, 'old-optional-hash', $now->modify('+1 hour'));
        $store->discard(1);
        self::assertFalse($store->matches(1, 'old-optional-hash', $now));
        $store->discard(1);
        self::assertSame(0, $store->prune($now));
    }

    public function testCapacityFailsClosedWithoutDiscardingExistingChallenge(): void
    {
        $store = new InMemoryTemporaryRescueTokenStore(1);
        $expires = new DateTimeImmutable('2026-01-01');
        $store->replace(1, 'a', $expires);
        $store->replace(1, 'b', $expires);
        $this->expectException(OverflowException::class);
        $store->replace(2, 'c', $expires);
    }

    public function testInvalidCapacityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InMemoryTemporaryRescueTokenStore(0);
    }
}
