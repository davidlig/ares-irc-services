<?php

declare(strict_types=1);

namespace App\Tests\Application\Maintenance\Message;

use App\Application\Maintenance\MaintenanceScheduler;
use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Application\Maintenance\Message\RunMaintenanceCycle;
use App\Application\Maintenance\Message\RunMaintenanceCycleHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;

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

    #[Test]
    public function invokeCausesSchedulerToRunRegisteredTasks(): void
    {
        $holder = new stdClass();
        $holder->ran = false;
        $task = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'handler_test.task';
            }

            public function getIntervalSeconds(): int
            {
                return 0;
            }

            public function getOrder(): int
            {
                return 100;
            }

            public function run(): void
            {
                $this->holder->ran = true;
            }
        };

        $scheduler = new MaintenanceScheduler([$task], new NullLogger());
        $handler = new RunMaintenanceCycleHandler($scheduler);
        $handler(new RunMaintenanceCycle());

        self::assertTrue($holder->ran, 'Handler invocation runs scheduler tick which executes registered task');
    }
}
