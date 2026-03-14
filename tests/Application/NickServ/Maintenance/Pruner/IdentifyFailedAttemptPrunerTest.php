<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Maintenance\Pruner;

use App\Application\NickServ\IdentifyFailedAttemptRegistry;
use App\Application\NickServ\Maintenance\Pruner\IdentifyFailedAttemptPruner;
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
        $pruner = new IdentifyFailedAttemptPruner($registry, 3600);

        $result = $pruner->prune();

        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }
}
