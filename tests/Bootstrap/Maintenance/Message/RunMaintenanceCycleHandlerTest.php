<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Maintenance\Message;

use App\Bootstrap\Maintenance\MaintenanceScheduler;
use App\Bootstrap\Maintenance\MaintenanceTaskInterface;
use App\Bootstrap\Maintenance\Message\RunMaintenanceCycle;
use App\Bootstrap\Maintenance\Message\RunMaintenanceCycleHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use stdClass;

#[CoversClass(RunMaintenanceCycleHandler::class)]
final class RunMaintenanceCycleHandlerTest extends TestCase
{
    #[Test]
    public function invokeCausesSchedulerToRunRegisteredTasks(): void
    {
        $holder = new stdClass();
        $holder->ran = false;
        $task = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder) {}

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
