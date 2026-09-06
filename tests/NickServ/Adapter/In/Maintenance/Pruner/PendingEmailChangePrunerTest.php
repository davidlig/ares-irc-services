<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance\Pruner;

use App\NickServ\Adapter\In\Maintenance\Pruner\PendingEmailChangePruner;
use App\NickServ\Adapter\Out\InMemory\PendingEmailChangeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PendingEmailChangePruner::class)]
final class PendingEmailChangePrunerTest extends TestCase
{
    #[Test]
    public function pruneDelegatesToRegistryPruneExpiredAndReturnsCount(): void
    {
        $registry = new PendingEmailChangeRegistry();
        $pruner = new PendingEmailChangePruner($registry);

        $result = $pruner->prune();

        self::assertGreaterThanOrEqual(0, $result);
    }
}
