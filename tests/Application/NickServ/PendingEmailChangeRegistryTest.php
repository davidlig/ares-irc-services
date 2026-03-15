<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\PendingEmailChangeRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(PendingEmailChangeRegistry::class)]
final class PendingEmailChangeRegistryTest extends TestCase
{
    #[Test]
    public function hasReturnsFalseInitially(): void
    {
        $registry = new PendingEmailChangeRegistry();
        self::assertFalse($registry->has('nick'));
    }

    #[Test]
    public function storeAndHasAndConsume(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick', 'new@example.com', 'token123');
        self::assertTrue($registry->has('nick'));
        self::assertTrue($registry->consume('Nick', 'new@example.com', 'token123'));
        self::assertFalse($registry->has('nick'));
    }

    #[Test]
    public function consumeReturnsFalseWhenNoEntry(): void
    {
        $registry = new PendingEmailChangeRegistry();
        self::assertFalse($registry->consume('unknown', 'a@b.com', 't'));
    }

    #[Test]
    public function consumeReturnsFalseWhenTokenMismatch(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick', 'new@example.com', 'token123');
        self::assertFalse($registry->consume('Nick', 'new@example.com', 'wrong'));
        self::assertTrue($registry->has('nick'));
    }

    #[Test]
    public function consumeReturnsFalseWhenEmailMismatch(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick', 'new@example.com', 'token123');
        self::assertFalse($registry->consume('Nick', 'other@example.com', 'token123'));
    }

    #[Test]
    public function removeDeletesEntry(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick', 'a@b.com', 't');
        $registry->remove('nick');
        self::assertFalse($registry->has('nick'));
    }

    #[Test]
    public function pruneExpiredReturnsCountOfRemoved(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick', 'a@b.com', 't');
        $removed = $registry->pruneExpired();
        self::assertGreaterThanOrEqual(0, $removed);
    }

    #[Test]
    public function consumeIsCaseInsensitiveForEmail(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick', 'new@example.com', 'token123');
        self::assertTrue($registry->consume('Nick', 'NEW@EXAMPLE.COM', 'token123'));
        self::assertFalse($registry->has('nick'));
    }

    #[Test]
    public function hasIsCaseInsensitive(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('TestNick', 'a@b.com', 't');
        self::assertTrue($registry->has('testnick'));
        self::assertTrue($registry->has('TESTNICK'));
    }

    #[Test]
    public function removeIsCaseInsensitive(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('TestNick', 'a@b.com', 't');
        $registry->remove('TESTNICK');
        self::assertFalse($registry->has('testnick'));
    }

    #[Test]
    public function consumeRemovesEntryOnSuccess(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick', 'new@example.com', 'token123');
        self::assertTrue($registry->consume('Nick', 'new@example.com', 'token123'));
        self::assertFalse($registry->has('nick'));
        self::assertFalse($registry->consume('Nick', 'new@example.com', 'token123'));
    }

    #[Test]
    public function consumeReturnsFalseAndRemovesExpiredEntry(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick', 'new@example.com', 'token123');

        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('entries');
        $property->setAccessible(true);

        $entries = $property->getValue($registry);
        $entries['nick']['expiresAt'] = new DateTimeImmutable('-1 hour');
        $property->setValue($registry, $entries);

        self::assertFalse($registry->consume('Nick', 'new@example.com', 'token123'));
        self::assertFalse($registry->has('nick'));
    }

    #[Test]
    public function pruneExpiredRemovesExpiredEntries(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $registry->store('Nick1', 'a@b.com', 'token1');

        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('entries');
        $property->setAccessible(true);

        $entries = $property->getValue($registry);
        $entries['nick1']['expiresAt'] = new DateTimeImmutable('-1 hour');
        $property->setValue($registry, $entries);

        $registry->store('Nick2', 'c@d.com', 'token2');

        $removed = $registry->pruneExpired();

        self::assertSame(1, $removed);
        self::assertFalse($registry->has('nick1'));
        self::assertTrue($registry->has('nick2'));
    }
}
