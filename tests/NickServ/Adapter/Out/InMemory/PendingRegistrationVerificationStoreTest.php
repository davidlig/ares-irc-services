<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\NickServ\Adapter\Out\InMemory\PendingRegistrationVerificationStore;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingRegistrationVerificationStore::class)]
final class PendingRegistrationVerificationStoreTest extends TestCase
{
    #[Test]
    public function storesTheChallengeInRegistry(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
        $registry = new PendingVerificationRegistry();
        $adapter = new PendingRegistrationVerificationStore($registry);

        $adapter->store('MixedNick', 'token', $now->modify('+1 hour'));

        self::assertTrue($registry->has('mixednick'));
        self::assertTrue($registry->consume('MIXEDNICK', 'token', $now));
    }

    #[Test]
    public function consumesTheChallengeViaAdapter(): void
    {
        $now = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
        $registry = new PendingVerificationRegistry();
        $adapter = new PendingRegistrationVerificationStore($registry);

        $adapter->store('MixedNick', 'token', $now->modify('+1 hour'));

        self::assertTrue($adapter->consume('MIXEDNICK', 'token', $now));
        self::assertFalse($adapter->consume('MIXEDNICK', 'token', $now));
    }
}
