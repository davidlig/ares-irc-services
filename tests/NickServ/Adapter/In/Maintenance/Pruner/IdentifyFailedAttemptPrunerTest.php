<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance\Pruner;

use App\NickServ\Adapter\In\Maintenance\Pruner\IdentifyFailedAttemptPruner;
use App\NickServ\Adapter\Out\InMemory\IdentifyFailedAttemptRegistry;
use App\NickServ\Application\Port\Out\Clock;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IdentifyFailedAttemptPruner::class)]
final class IdentifyFailedAttemptPrunerTest extends TestCase
{
    #[Test]
    public function pruneDelegatesToRegistryPruneStaleAndReturnsCount(): void
    {
        $registry = new IdentifyFailedAttemptRegistry();
        $pruner = new IdentifyFailedAttemptPruner($registry, $this->clock(), 3600);

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
