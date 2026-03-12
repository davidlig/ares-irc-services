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
}
