<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\Antiflood;

use App\Irc\Application\Antiflood\AntifloodPruner;
use App\Irc\Application\Antiflood\AntifloodRegistry;
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

        self::assertSame(0, $pruner->prune());
    }

    #[Test]
    public function pruneRemovesStaleEntries(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = time() - 3600;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setValue($registry, ['key1' => [$oldTimestamp]]);

        $pruner = new AntifloodPruner($registry, 60);

        self::assertSame(1, $pruner->prune());
    }
}
