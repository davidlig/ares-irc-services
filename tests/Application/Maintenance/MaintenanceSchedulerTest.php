<?php

declare(strict_types=1);

namespace App\Tests\Application\Maintenance;

use App\Application\Maintenance\MaintenanceScheduler;
use App\Application\Maintenance\MaintenanceTaskInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;

#[CoversClass(MaintenanceScheduler::class)]
final class MaintenanceSchedulerTest extends TestCase
{
    #[Test]
    public function tickRunsTasksInOrderWhenIntervalElapsed(): void
    {
        $holder = new stdClass();
        $holder->runOrder = [];
        $taskA = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'task.a';
            }

            public function getIntervalSeconds(): int
            {
                return 0;
            }

            public function getOrder(): int
            {
                return 200;
            }

            public function run(): void
            {
                $this->holder->runOrder[] = 'A';
            }
        };
        $taskB = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'task.b';
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
                $this->holder->runOrder[] = 'B';
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $scheduler = new MaintenanceScheduler([$taskA, $taskB], $logger);

        $scheduler->tick();

        self::assertSame(['B', 'A'], $holder->runOrder);
    }

    #[Test]
    public function tickStopsCycleWhenTaskThrows(): void
    {
        $holder = new stdClass();
        $holder->runOrder = [];
        $taskOk = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'task.ok';
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
                $this->holder->runOrder[] = 'ok';
            }
        };
        $taskFails = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'task.fails';
            }

            public function getIntervalSeconds(): int
            {
                return 0;
            }

            public function getOrder(): int
            {
                return 200;
            }

            public function run(): void
            {
                $this->holder->runOrder[] = 'fail';
                throw new RuntimeException('Task failed');
            }
        };
        $taskNever = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'task.never';
            }

            public function getIntervalSeconds(): int
            {
                return 0;
            }

            public function getOrder(): int
            {
                return 300;
            }

            public function run(): void
            {
                $this->holder->runOrder[] = 'never';
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $scheduler = new MaintenanceScheduler([$taskOk, $taskFails, $taskNever], $logger);

        $scheduler->tick();

        self::assertSame(['ok', 'fail'], $holder->runOrder);
    }

    #[Test]
    public function tickRunsTaskWithZeroIntervalEveryTime(): void
    {
        $holder = new stdClass();
        $holder->runCount = 0;
        $task = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'task.zero_interval';
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
                ++$this->holder->runCount;
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $scheduler = new MaintenanceScheduler([$task], $logger);

        $scheduler->tick();
        $scheduler->tick();

        self::assertSame(2, $holder->runCount);
    }

    #[Test]
    public function tickSkipsTaskWhenIntervalNotElapsed(): void
    {
        $holder = new stdClass();
        $holder->runCount = 0;
        $task = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly stdClass $holder)
            {
            }

            public function getName(): string
            {
                return 'task.long_interval';
            }

            public function getIntervalSeconds(): int
            {
                return 3600;
            }

            public function getOrder(): int
            {
                return 100;
            }

            public function run(): void
            {
                ++$this->holder->runCount;
            }
        };

        $logger = $this->createStub(LoggerInterface::class);
        $scheduler = new MaintenanceScheduler([$task], $logger);

        $scheduler->tick();
        $scheduler->tick();

        self::assertSame(1, $holder->runCount);
    }

    #[Test]
    public function tickLogsCycleStartedTaskExecutedAndCycleCompleted(): void
    {
        $task = new class implements MaintenanceTaskInterface {
            public function getName(): string
            {
                return 'task.logged';
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
            }
        };

        $logMessages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message) use (&$logMessages): void {
            $logMessages[] = $message;
        });

        $scheduler = new MaintenanceScheduler([$task], $logger);
        $scheduler->tick();

        self::assertSame('Maintenance cycle started', $logMessages[0]);
        self::assertSame('Maintenance task executed', $logMessages[1]);
        self::assertSame('Maintenance cycle completed', $logMessages[2]);
    }

    #[Test]
    public function tickLogsCycleAbortedWhenTaskThrows(): void
    {
        $task = new class implements MaintenanceTaskInterface {
            public function getName(): string
            {
                return 'task.throws';
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
                throw new RuntimeException('Task error');
            }
        };

        $logErrors = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (): void {
        });
        $logger->method('error')->willReturnCallback(static function (string $message) use (&$logErrors): void {
            $logErrors[] = $message;
        });

        $scheduler = new MaintenanceScheduler([$task], $logger);
        $scheduler->tick();

        self::assertSame('Maintenance cycle aborted', $logErrors[0]);
    }
}
