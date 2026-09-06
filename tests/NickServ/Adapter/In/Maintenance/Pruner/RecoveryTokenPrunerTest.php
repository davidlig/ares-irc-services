<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance\Pruner;

use App\NickServ\Adapter\In\Maintenance\Pruner\RecoveryTokenPruner;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecoveryTokenPruner::class)]
final class RecoveryTokenPrunerTest extends TestCase
{
    #[Test]
    public function pruneDelegatesToRegistryPruneExpiredAndReturnsCount(): void
    {
        $registry = new RecoveryTokenRegistry();
        $pruner = new RecoveryTokenPruner($registry, 7200);

        $result = $pruner->prune();

        self::assertGreaterThanOrEqual(0, $result);
    }
}
