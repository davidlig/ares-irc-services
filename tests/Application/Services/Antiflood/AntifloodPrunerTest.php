<?php

declare(strict_types=1);

namespace App\Tests\Application\Services\Antiflood;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\Services\Antiflood\AntifloodPruner;
use App\Application\Services\Antiflood\AntifloodRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AntifloodPruner::class)]
final class AntifloodPrunerTest extends TestCase
{
    #[Test]
    public function pruneDelegatesToRegistry(): void
    {
        $registry = new AntifloodRegistry();
        $pruner = new AntifloodPruner($registry, 3600);

        self::assertInstanceOf(InMemoryPrunableInterface::class, $pruner);
        self::assertSame(0, $pruner->prune());
    }

    #[Test]
    public function pruneRemovesStaleEntries(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 3600;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setAccessible(true);
        $property->setValue($registry, ['key1' => [$oldTimestamp]]);

        $pruner = new AntifloodPruner($registry, 60);

        self::assertSame(1, $pruner->prune());
    }
}
