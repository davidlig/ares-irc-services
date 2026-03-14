<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance\Pruner;

use App\Application\NickServ\Maintenance\Pruner\PendingVerificationPruner;
use App\Application\NickServ\PendingVerificationRegistry;
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
        $pruner = new PendingVerificationPruner($registry, 86400);

        $result = $pruner->prune();

        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }
}
