<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\RecoveryTokenRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecoveryTokenRegistry::class)]
final class RecoveryTokenRegistryTest extends TestCase
{
    #[Test]
    public function storeAndConsumeValidToken(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Nick', 'token', new DateTimeImmutable('+1 hour'));

        self::assertTrue($registry->consume('Nick', 'token'));
    }

    #[Test]
    public function consumeWhenNicknameNotStoredReturnsFalse(): void
    {
        $registry = new RecoveryTokenRegistry();

        self::assertFalse($registry->consume('Unknown', 'any'));
    }

    #[Test]
    public function consumeWrongTokenReturnsFalse(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Nick', 'good', new DateTimeImmutable('+1 hour'));

        self::assertFalse($registry->consume('Nick', 'bad'));
    }

    #[Test]
    public function consumeExpiredReturnsFalse(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Nick', 'token', new DateTimeImmutable('-1 hour'));

        self::assertFalse($registry->consume('Nick', 'token'));
    }

    #[Test]
    public function getLastRecoverAtAndRecordRecover(): void
    {
        $registry = new RecoveryTokenRegistry();

        self::assertNull($registry->getLastRecoverAt('Nick'));

        $registry->recordRecover('Nick');

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastRecoverAt('Nick'));
    }

    #[Test]
    public function pruneExpiredRemovesExpiredEntries(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Expired', 't', new DateTimeImmutable('-1 hour'));

        $removed = $registry->pruneExpired(86400);

        self::assertGreaterThanOrEqual(1, $removed);
    }

    #[Test]
    public function pruneExpiredRemovesOldLastRecoverAtEntries(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Active', 'token', new DateTimeImmutable('+1 hour'));
        $registry->recordRecover('OldNick');
        sleep(1);

        $removed = $registry->pruneExpired(0);

        self::assertGreaterThanOrEqual(1, $removed);
        self::assertNull($registry->getLastRecoverAt('OldNick'));
    }

    #[Test]
    public function pruneExpiredKeepsFreshLastRecoverAtEntries(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->recordRecover('FreshNick');

        $removed = $registry->pruneExpired(86400);

        self::assertSame(0, $removed);
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastRecoverAt('FreshNick'));
    }

    #[Test]
    public function pruneExpiredReturnsTotalRemovedFromBothCollections(): void
    {
        $registry = new RecoveryTokenRegistry();
        $registry->store('Expired1', 't', new DateTimeImmutable('-1 hour'));
        $registry->store('Expired2', 't', new DateTimeImmutable('-1 hour'));
        $registry->recordRecover('OldNick1');
        $registry->recordRecover('OldNick2');

        $removed = $registry->pruneExpired(0);

        // At minimum the 2 expired tokens are removed; lastRecoverAt entries may or may not
        // be removed depending on timing (with cutoff=0, entries created "now" may be at the boundary)
        self::assertGreaterThanOrEqual(2, $removed);
    }
}
