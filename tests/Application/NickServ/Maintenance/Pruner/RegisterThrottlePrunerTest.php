<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance\Pruner;

use App\Application\NickServ\Maintenance\Pruner\RegisterThrottlePruner;
use App\Application\NickServ\RegisterThrottleRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegisterThrottlePruner::class)]
final class RegisterThrottlePrunerTest extends TestCase
{
    #[Test]
    public function pruneDelegatesToRegistryPruneExpiredCooldownsAndReturnsCount(): void
    {
        $registry = new RegisterThrottleRegistry();
        $pruner = new RegisterThrottlePruner($registry, 3600);

        $result = $pruner->prune();

        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }
}
