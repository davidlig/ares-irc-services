<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Maintenance;

use App\Irc\Adapter\In\Maintenance\MaintenanceScheduler;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\Maintenance\PrerequisiteAwareMaintenanceTaskInterface;
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

    #[Test]
    public function tickSkipsATaskWhosePrerequisiteFailedAndStillRunsUnrelatedTasks(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $warnings = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message, array $context = []) use (&$warnings): void {
            $warnings[] = [$message, $context];
        });
        $infos = [];
        $logger->method('info')->willReturnCallback(static function (string $message, array $context = []) use (&$infos): void {
            $infos[] = [$message, $context];
        });

        $scheduler = new MaintenanceScheduler([
            new MaintenanceSchedulerTestTask($holder, 'task.flush', 100, fails: true),
            new MaintenanceSchedulerTestDependentTask($holder, 'task.purge', 200, ['task.flush']),
            new MaintenanceSchedulerTestTask($holder, 'task.unrelated', 300),
        ], $logger);

        $scheduler->tick();

        self::assertSame(['task.flush', 'task.unrelated'], $holder->runOrder);
        self::assertSame([[
            'Maintenance task skipped: a prerequisite failed in this cycle',
            ['task' => 'task.purge', 'prerequisite' => 'task.flush'],
        ]], $warnings);
        $completed = end($infos);
        self::assertIsArray($completed);
        self::assertSame(['task.unrelated'], $completed[1]['tasks_run']);
        self::assertSame(['task.flush'], $completed[1]['tasks_failed']);
        self::assertSame(['task.purge'], $completed[1]['tasks_skipped']);
    }

    #[Test]
    public function tickSkipsATaskWhosePrerequisiteWasSkippedForAFailedPrerequisite(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $warnings = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(static function (string $message, array $context = []) use (&$warnings): void {
            $warnings[] = $context;
        });

        $scheduler = new MaintenanceScheduler([
            new MaintenanceSchedulerTestTask($holder, 'task.a', 100, fails: true),
            new MaintenanceSchedulerTestDependentTask($holder, 'task.b', 200, ['task.a']),
            new MaintenanceSchedulerTestDependentTask($holder, 'task.c', 300, ['task.b']),
        ], $logger);

        $scheduler->tick();

        self::assertSame(['task.a'], $holder->runOrder);
        self::assertSame([
            ['task' => 'task.b', 'prerequisite' => 'task.a'],
            ['task' => 'task.c', 'prerequisite' => 'task.b'],
        ], $warnings);
    }

    #[Test]
    public function tickRunsATaskWhosePrerequisiteWasNotDueThisCycle(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $scheduler = new MaintenanceScheduler([
            new MaintenanceSchedulerTestTask($holder, 'task.hourly', 100, intervalSeconds: 3600),
            new MaintenanceSchedulerTestDependentTask($holder, 'task.dependent', 200, ['task.hourly']),
        ], $this->createStub(LoggerInterface::class));

        $scheduler->tick();
        $scheduler->tick();

        self::assertSame(['task.hourly', 'task.dependent', 'task.dependent'], $holder->runOrder);
    }

    #[Test]
    public function tickRunsASkippedTaskOnTheNextTickOnceItsPrerequisiteSucceeds(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $flush = new MaintenanceSchedulerTestTask($holder, 'task.flush', 100, fails: true);
        $scheduler = new MaintenanceScheduler([
            $flush,
            new MaintenanceSchedulerTestDependentTask($holder, 'task.purge', 200, ['task.flush'], intervalSeconds: 3600),
        ], $this->createStub(LoggerInterface::class));

        $scheduler->tick();
        $flush->fails = false;
        $scheduler->tick();
        $scheduler->tick();

        self::assertSame(['task.flush', 'task.flush', 'task.purge', 'task.flush'], $holder->runOrder);
    }
}

final class MaintenanceSchedulerTestState
{
    /** @var list<string> */
    public array $runOrder = [];

    public int $runCount = 0;
}

class MaintenanceSchedulerTestTask implements MaintenanceTaskInterface
{
    public function __construct(
        private readonly MaintenanceSchedulerTestState $holder,
        private readonly string $name,
        private readonly int $order,
        public bool $fails = false,
        private readonly int $intervalSeconds = 0,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function run(): void
    {
        $this->holder->runOrder[] = $this->name;
        if ($this->fails) {
            throw new RuntimeException($this->name . ' failed');
        }
    }
}

final class MaintenanceSchedulerTestDependentTask extends MaintenanceSchedulerTestTask implements PrerequisiteAwareMaintenanceTaskInterface
{
    /**
     * @param list<string> $prerequisites
     */
    public function __construct(
        MaintenanceSchedulerTestState $holder,
        string $name,
        int $order,
        private readonly array $prerequisites,
        int $intervalSeconds = 0,
    ) {
        parent::__construct($holder, $name, $order, false, $intervalSeconds);
    }

    public function getPrerequisiteTaskNames(): array
    {
        return $this->prerequisites;
    }
}
