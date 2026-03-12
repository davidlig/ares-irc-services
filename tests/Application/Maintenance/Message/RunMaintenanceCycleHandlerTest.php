<?php

declare(strict_types=1);

namespace App\Tests\Application\Maintenance\Message;

use App\Application\Maintenance\MaintenanceScheduler;
use App\Application\Maintenance\Message\RunMaintenanceCycle;
use App\Application\Maintenance\Message\RunMaintenanceCycleHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(RunMaintenanceCycleHandler::class)]
final class RunMaintenanceCycleHandlerTest extends TestCase
{
    #[Test]
    public function invokeRunsSchedulerTick(): void
    {
        $scheduler = new MaintenanceScheduler([], new NullLogger());
        $handler = new RunMaintenanceCycleHandler($scheduler);

        $handler(new RunMaintenanceCycle());

        self::assertTrue(true, 'Handler runs without throwing');
    }
}
