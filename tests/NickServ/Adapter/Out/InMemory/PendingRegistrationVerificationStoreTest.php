<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\InMemory;

use App\Application\NickServ\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\PendingRegistrationVerificationStore;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingRegistrationVerificationStore::class)]
final class PendingRegistrationVerificationStoreTest extends TestCase
{
    #[Test]
    public function storesTheChallengeInTheLegacyRegistryDuringMigration(): void
    {
        $registry = new PendingVerificationRegistry();
        $adapter = new PendingRegistrationVerificationStore($registry);

        $adapter->store('MixedNick', 'token', new DateTimeImmutable('+1 hour'));

        self::assertTrue($registry->has('mixednick'));
        self::assertTrue($registry->consume('MIXEDNICK', 'token'));
    }
}
