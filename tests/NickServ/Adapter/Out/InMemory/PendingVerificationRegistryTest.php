<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingVerificationRegistry::class)]
final class PendingVerificationRegistryTest extends TestCase
{
    #[Test]
    public function storeAndHasAndRemove(): void
    {
        $registry = new PendingVerificationRegistry();
        $expiresAt = $this->now()->modify('+1 hour');

        self::assertFalse($registry->has('Nick'));

        $registry->store('Nick', 'token123', $expiresAt);

        self::assertTrue($registry->has('Nick'));
        self::assertTrue($registry->has('nick'));

        $registry->remove('NICK');

        self::assertFalse($registry->has('Nick'));
    }

    #[Test]
    public function consumeValidTokenReturnsTrueAndRemovesEntry(): void
    {
        $registry = new PendingVerificationRegistry();
        $expiresAt = $this->now()->modify('+1 hour');
        $registry->store('Nick', 'secret', $expiresAt);

        self::assertTrue($registry->consume('Nick', 'secret', $this->now()));
        self::assertFalse($registry->has('Nick'));
    }

    #[Test]
    public function consumeWrongTokenReturnsFalse(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->store('Nick', 'secret', $this->now()->modify('+1 hour'));

        self::assertFalse($registry->consume('Nick', 'wrong', $this->now()));
        self::assertTrue($registry->has('Nick'));
    }

    #[Test]
    public function consumeExpiredTokenReturnsFalseAndRemovesEntry(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->store('Nick', 'secret', $this->now()->modify('-1 hour'));

        self::assertFalse($registry->consume('Nick', 'secret', $this->now()));
        self::assertFalse($registry->has('Nick'));
    }

    #[Test]
    public function consumeMissingNickReturnsFalse(): void
    {
        $registry = new PendingVerificationRegistry();

        self::assertFalse($registry->consume('Nobody', 'token', $this->now()));
    }

    #[Test]
    public function getLastResendAtAndRecordResend(): void
    {
        $registry = new PendingVerificationRegistry();

        self::assertNull($registry->getLastResendAt('Nick'));

        $registry->recordResend('Nick', $this->now());

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastResendAt('Nick'));
    }

    #[Test]
    public function pruneExpiredRemovesExpiredEntries(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->store('Expired', 't', $this->now()->modify('-1 hour'));
        $registry->store('Valid', 't', $this->now()->modify('+1 hour'));
        $registry->recordResend('OldResend', $this->now()->modify('-2 seconds'));

        $removed = $registry->pruneExpired($this->now(), 1);

        self::assertGreaterThanOrEqual(1, $removed);
        self::assertFalse($registry->has('Expired'));
    }

    #[Test]
    public function pruneExpiredRemovesOldLastResendAtEntries(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->store('Active', 'token', $this->now()->modify('+1 hour'));
        $registry->recordResend('OldNick', $this->now()->modify('-2 seconds'));

        $removed = $registry->pruneExpired($this->now(), 1);

        self::assertSame(1, $removed);
        self::assertNull($registry->getLastResendAt('OldNick'));
    }

    #[Test]
    public function pruneExpiredKeepsFreshLastResendAtEntries(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->recordResend('FreshNick', $this->now());

        $removed = $registry->pruneExpired($this->now(), 86400);

        self::assertSame(0, $removed);
        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastResendAt('FreshNick'));
    }

    #[Test]
    public function pruneExpiredReturnsTotalRemovedFromBothCollections(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->store('Expired1', 't', $this->now()->modify('-1 hour'));
        $registry->store('Expired2', 't', $this->now()->modify('-1 hour'));
        $registry->recordResend('OldNick1', $this->now()->modify('-2 seconds'));
        $registry->recordResend('OldNick2', $this->now()->modify('-2 seconds'));

        $removed = $registry->pruneExpired($this->now(), 1);

        self::assertSame(4, $removed);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
