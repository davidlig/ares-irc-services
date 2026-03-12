<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\PendingVerificationRegistry;
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
        $expiresAt = new DateTimeImmutable('+1 hour');

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
        $expiresAt = new DateTimeImmutable('+1 hour');
        $registry->store('Nick', 'secret', $expiresAt);

        self::assertTrue($registry->consume('Nick', 'secret'));
        self::assertFalse($registry->has('Nick'));
    }

    #[Test]
    public function consumeWrongTokenReturnsFalse(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->store('Nick', 'secret', new DateTimeImmutable('+1 hour'));

        self::assertFalse($registry->consume('Nick', 'wrong'));
        self::assertTrue($registry->has('Nick'));
    }

    #[Test]
    public function consumeExpiredTokenReturnsFalseAndRemovesEntry(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->store('Nick', 'secret', new DateTimeImmutable('-1 hour'));

        self::assertFalse($registry->consume('Nick', 'secret'));
        self::assertFalse($registry->has('Nick'));
    }

    #[Test]
    public function consumeMissingNickReturnsFalse(): void
    {
        $registry = new PendingVerificationRegistry();

        self::assertFalse($registry->consume('Nobody', 'token'));
    }

    #[Test]
    public function getLastResendAtAndRecordResend(): void
    {
        $registry = new PendingVerificationRegistry();

        self::assertNull($registry->getLastResendAt('Nick'));

        $registry->recordResend('Nick');

        self::assertInstanceOf(DateTimeImmutable::class, $registry->getLastResendAt('Nick'));
    }

    #[Test]
    public function pruneExpiredRemovesExpiredEntries(): void
    {
        $registry = new PendingVerificationRegistry();
        $registry->store('Expired', 't', new DateTimeImmutable('-1 hour'));
        $registry->store('Valid', 't', new DateTimeImmutable('+1 hour'));
        $registry->recordResend('OldResend');

        $removed = $registry->pruneExpired(1);

        self::assertGreaterThanOrEqual(1, $removed);
        self::assertFalse($registry->has('Expired'));
    }
}
