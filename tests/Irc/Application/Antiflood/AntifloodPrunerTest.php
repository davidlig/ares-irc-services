<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\Antiflood;

use App\Irc\Application\Antiflood\AntifloodPruner;
use App\Irc\Application\Antiflood\AntifloodRegistry;
use App\Irc\Application\Port\Out\AntifloodClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(AntifloodPruner::class)]
final class AntifloodPrunerTest extends TestCase
{
    private const int NOW = 1_750_000_000;

    #[Test]
    public function pruneDelegatesToRegistry(): void
    {
        $registry = new AntifloodRegistry();
        $pruner = new AntifloodPruner($registry, $this->clock(), 3600);

        self::assertSame(0, $pruner->prune());
    }

    #[Test]
    public function pruneRemovesStaleEntries(): void
    {
        $registry = new AntifloodRegistry();

        $oldTimestamp = self::NOW - 3600;
        $reflection = new ReflectionClass($registry);
        $property = $reflection->getProperty('timestampsByKey');
        $property->setValue($registry, ['key1' => [$oldTimestamp]]);

        $pruner = new AntifloodPruner($registry, $this->clock(), 60);

        self::assertSame(1, $pruner->prune());
    }

    private function clock(): AntifloodClock
    {
        $clock = $this->createStub(AntifloodClock::class);
        $clock->method('now')->willReturn(self::NOW);

        return $clock;
    }
}
