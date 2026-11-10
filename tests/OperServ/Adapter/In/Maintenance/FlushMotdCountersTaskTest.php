<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Maintenance;

use App\OperServ\Adapter\In\Maintenance\FlushMotdCountersTask;
use App\OperServ\Application\Port\Out\MotdCounterFlusher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FlushMotdCountersTask::class)]
final class FlushMotdCountersTaskTest extends TestCase
{
    #[Test]
    public function flushesOnEveryMaintenanceCycle(): void
    {
        $flusher = $this->createMock(MotdCounterFlusher::class);
        $flusher->expects(self::once())->method('flushShownCounts');
        $task = new FlushMotdCountersTask($flusher);
        self::assertSame('operserv.flush_motd_counters', $task->getName());
        self::assertSame(0, $task->getIntervalSeconds());
        self::assertSame(364, $task->getOrder());
        $task->run();
    }
}
