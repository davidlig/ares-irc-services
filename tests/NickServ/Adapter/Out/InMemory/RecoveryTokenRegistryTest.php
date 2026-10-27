<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use DateTimeImmutable;
use OverflowException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function serialize;
use function strtolower;

#[CoversClass(RecoveryTokenRegistry::class)]
final class RecoveryTokenRegistryTest extends TestCase
{
    #[Test]
    public function storeAndConsumeValidToken(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Nick', 'token', $this->now()->modify('+1 hour'));

        self::assertTrue($registry->consume('Nick', 'token', $this->now()));
    }

    #[Test]
    public function consumeWhenNicknameNotStoredReturnsFalse(): void
    {
        $registry = new RecoveryTokenRegistry();

        self::assertFalse($registry->consume('Unknown', 'any', $this->now()));
    }

    #[Test]
    public function consumeWrongTokenReturnsFalse(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Nick', 'good', $this->now()->modify('+1 hour'));

        self::assertFalse($registry->consume('Nick', 'bad', $this->now()));
    }

    #[Test]
    public function consumeExpiredReturnsFalse(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Nick', 'token', $this->now()->modify('-1 hour'));

        self::assertFalse($registry->consume('Nick', 'token', $this->now()));
    }

    #[Test]
    public function getLastRecoverAtAndRecordRecover(): void
    {
        $registry = new RecoveryTokenRegistry();

        self::assertNull($registry->getLastRecoverAt('Nick'));

        $registry->recordRecover('Nick', $this->now());

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastRecoverAt('Nick'));
    }

    #[Test]
    public function pruneExpiredRemovesExpiredEntries(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Expired', 't', $this->now()->modify('-1 hour'));

        $removed = $registry->pruneExpired($this->now(), 86400);

        self::assertGreaterThanOrEqual(1, $removed);
    }

    #[Test]
    public function pruneExpiredRemovesOldLastRecoverAtEntries(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Active', 'token', $this->now()->modify('+1 hour'));
        $registry->recordRecover('OldNick', $this->now());

        // Simulate aging by setting lastRecoverAt to an old timestamp
        $reflection = new ReflectionClass($registry);
        $prop = $reflection->getProperty('lastRecoverAt');
        /** @var array<string, DateTimeImmutable> $lastRecoverAt */
        $lastRecoverAt = $prop->getValue($registry);
        $lastRecoverAt[strtolower('OldNick')] = $this->now()->modify('-2 seconds');
        $prop->setValue($registry, $lastRecoverAt);

        $removed = $registry->pruneExpired($this->now(), 1);

        self::assertGreaterThanOrEqual(1, $removed);
        self::assertNull($registry->getLastRecoverAt('OldNick'));
    }

    #[Test]
    public function pruneExpiredKeepsFreshLastRecoverAtEntries(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->recordRecover('FreshNick', $this->now());

        $removed = $registry->pruneExpired($this->now(), 86400);

        self::assertSame(0, $removed);
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastRecoverAt('FreshNick'));
    }

    #[Test]
    public function pruneExpiredReturnsTotalRemovedFromBothCollections(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Expired1', 't', $this->now()->modify('-1 hour'));
        $registry->store('Expired2', 't', $this->now()->modify('-1 hour'));
        $registry->recordRecover('OldNick1', $this->now()->modify('-2 seconds'));
        $registry->recordRecover('OldNick2', $this->now()->modify('-2 seconds'));

        $removed = $registry->pruneExpired($this->now(), 1);

        self::assertSame(4, $removed);
    }

    #[Test]
    public function matchingIsNonConsumingCanonicalAndNeverStoresPlaintext(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Nick', 'SECRET', $this->now()->modify('+1 hour'));
        self::assertTrue($registry->matches('nick', ' secret ', $this->now()));
        self::assertTrue($registry->matches('NICK', 'SECRET', $this->now()));
        self::assertStringNotContainsString('SECRET', serialize($registry));
        self::assertStringNotContainsString('secret', serialize($registry));
        $registry->store('Nick', 'replacement', $this->now()->modify('+1 hour'));
        $registry->removeIfMatches('Nick', 'secret');
        self::assertTrue($registry->matches('Nick', 'replacement', $this->now()));
        $registry->removeIfMatches('Nick', 'replacement');
        self::assertFalse($registry->matches('Nick', 'replacement', $this->now()));
    }

    #[Test]
    public function expiresExactlyAtBoundaryAndPrunesAtBoundary(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Nick', 'secret', $this->now());
        self::assertFalse($registry->matches('Nick', 'secret', $this->now()));
        $registry->store('Nick', 'secret', $this->now());
        self::assertSame(1, $registry->pruneExpired($this->now()));
    }

    #[Test]
    public function boundedCapacityPreservesExistingEntriesAndAllowsReplacement(): void
    {
        $registry = new RecoveryTokenRegistry(1);
        $registry->store('Nick', 'one', $this->now()->modify('+1 hour'));
        $registry->store('Nick', 'two', $this->now()->modify('+1 hour'));
        try {
            $registry->store('Other', 'three', $this->now()->modify('+1 hour'));
            self::fail('Capacity must fail closed.');
        } catch (OverflowException) {
            self::assertTrue($registry->matches('Nick', 'two', $this->now()));
        }
        $registry->recordRecover('Nick', $this->now());
        $registry->recordRecover('Nick', $this->now());
        $this->expectException(OverflowException::class);
        $registry->recordRecover('Other', $this->now());
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
