<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Maintenance;

use App\Irc\Adapter\In\Maintenance\MaintenanceScheduler;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function end;

#[CoversClass(MaintenanceScheduler::class)]
final class MaintenanceSchedulerTest extends TestCase
{
    #[Test]
    public function tickRunsTasksInOrderWhenIntervalElapsed(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $taskA = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly MaintenanceSchedulerTestState $holder) {}

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
            public function __construct(private readonly MaintenanceSchedulerTestState $holder) {}

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
    public function tickLogsAFailingTaskAndStillRunsLaterTasks(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $taskOk = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly MaintenanceSchedulerTestState $holder) {}

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
            public function __construct(private readonly MaintenanceSchedulerTestState $holder) {}

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
            public function __construct(private readonly MaintenanceSchedulerTestState $holder) {}

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

        self::assertSame(['ok', 'fail', 'never'], $holder->runOrder);

        $scheduler->tick();

        self::assertSame(['ok', 'fail', 'never', 'ok', 'fail', 'never'], $holder->runOrder);
    }

    #[Test]
    public function tickRunsTaskWithZeroIntervalEveryTime(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $task = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly MaintenanceSchedulerTestState $holder) {}

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
        $holder = new MaintenanceSchedulerTestState();
        $task = new class($holder) implements MaintenanceTaskInterface {
            public function __construct(private readonly MaintenanceSchedulerTestState $holder) {}

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

            public function run(): void {}
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
    public function tickLogsTheFailedTaskAndReportsItInTheCompletedCycle(): void
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
        $logInfos = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$logInfos): void {
            $logInfos[] = [$message, $context];
        });
        $logger->method('error')->willReturnCallback(static function (string $message, array $context = []) use (&$logErrors): void {
            $logErrors[] = [$message, $context];
        });

        $scheduler = new MaintenanceScheduler([$task], $logger);
        $scheduler->tick();

        self::assertCount(1, $logErrors);
        self::assertSame('Maintenance task failed', $logErrors[0][0]);
        self::assertSame('task.throws', $logErrors[0][1]['task']);
        self::assertSame('Task error', $logErrors[0][1]['error']);
        self::assertInstanceOf(RuntimeException::class, $logErrors[0][1]['exception']);
        $completed = end($logInfos);
        self::assertIsArray($completed);
        self::assertSame('Maintenance cycle completed', $completed[0]);
        self::assertSame([], $completed[1]['tasks_run']);
        self::assertSame(['task.throws'], $completed[1]['tasks_failed']);
    }
}

final class MaintenanceSchedulerTestState
{
    /** @var list<string> */
    public array $runOrder = [];

    public int $runCount = 0;
}
