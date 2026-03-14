<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance\Pruner;

use App\Application\NickServ\Maintenance\Pruner\PendingEmailChangePruner;
use App\Application\NickServ\PendingEmailChangeRegistry;
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

        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }
}
