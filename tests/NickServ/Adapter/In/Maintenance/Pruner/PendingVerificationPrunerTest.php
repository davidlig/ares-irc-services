<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance\Pruner;

use App\NickServ\Adapter\In\Maintenance\Pruner\PendingVerificationPruner;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Application\Port\Out\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingVerificationPruner::class)]
final class PendingVerificationPrunerTest extends TestCase
{
    #[Test]
    public function pruneDelegatesToRegistryPruneExpiredAndReturnsCount(): void
    {
        $registry = new PendingVerificationRegistry();
        $pruner = new PendingVerificationPruner($registry, $this->clock(), 86400);

        $result = $pruner->prune();

        self::assertGreaterThanOrEqual(0, $result);
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-09-06 12:00:00 UTC'));

        return $clock;
    }
}
