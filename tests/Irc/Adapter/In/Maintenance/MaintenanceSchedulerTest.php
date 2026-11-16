<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Maintenance;

use App\Irc\Adapter\In\Maintenance\MaintenanceScheduler;
use App\Irc\Adapter\Out\Persistence\ClosedEntityManagerReset;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\Maintenance\PrerequisiteAwareMaintenanceTaskInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
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

    #[Test]
    public function aFailedTaskThatClosedTheEntityManagerResetsItSoLaterTasksRun(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $persistence = new MaintenanceSchedulerTestPersistence();
        $closingTask = new MaintenanceSchedulerTestClosingTask($holder, $persistence, 'task.closing', 100);
        $laterTask = new MaintenanceSchedulerTestPersistenceTask($holder, $persistence, 'task.later', 200);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Maintenance: the EntityManager is closed, resetting it',
            ['after_task' => 'task.closing'],
        );

        $scheduler = new MaintenanceScheduler([$laterTask, $closingTask], $logger, new ClosedEntityManagerReset($this->registryFor($persistence)));
        $scheduler->tick();

        self::assertSame(['task.closing', 'task.later:open'], $holder->runOrder);
        self::assertSame(1, $persistence->resets);
    }

    #[Test]
    public function aFailedTaskWithAnOpenEntityManagerDoesNotResetIt(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $persistence = new MaintenanceSchedulerTestPersistence();
        $failingTask = new MaintenanceSchedulerTestTask($holder, 'task.failing', 100, true);
        $laterTask = new MaintenanceSchedulerTestPersistenceTask($holder, $persistence, 'task.later', 200);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('error')->with('Maintenance task failed', self::anything());

        $scheduler = new MaintenanceScheduler([$failingTask, $laterTask], $logger, new ClosedEntityManagerReset($this->registryFor($persistence)));
        $scheduler->tick();

        self::assertSame(['task.failing', 'task.later:open'], $holder->runOrder);
        self::assertSame(0, $persistence->resets);
    }

    #[Test]
    public function aCycleStartingWithAClosedEntityManagerResetsItBeforeTheFirstTask(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $persistence = new MaintenanceSchedulerTestPersistence();
        $persistence->closed = true;
        $task = new MaintenanceSchedulerTestPersistenceTask($holder, $persistence, 'task.only', 100);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Maintenance: the EntityManager is closed, resetting it',
            ['after_task' => null],
        );

        $scheduler = new MaintenanceScheduler([$task], $logger, new ClosedEntityManagerReset($this->registryFor($persistence)));
        $scheduler->tick();

        self::assertSame(['task.only:open'], $holder->runOrder);
        self::assertSame(1, $persistence->resets);
    }

    #[Test]
    public function aFailingEntityManagerResetIsLoggedAndTheCycleContinues(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $persistence = new MaintenanceSchedulerTestPersistence();
        $persistence->closed = true;
        $persistence->resetFailure = new RuntimeException('reset failed');
        $task = new MaintenanceSchedulerTestPersistenceTask($holder, $persistence, 'task.only', 100);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::once())->method('error')->with(
            'Maintenance: resetting the closed EntityManager failed',
            ['after_task' => null, 'exception' => $persistence->resetFailure],
        );

        $scheduler = new MaintenanceScheduler([$task], $logger, new ClosedEntityManagerReset($this->registryFor($persistence)));
        $scheduler->tick();

        self::assertSame(['task.only:closed'], $holder->runOrder);
    }

    #[Test]
    public function aManagerThatIsNotAnEntityManagerIsNeverReset(): void
    {
        $holder = new MaintenanceSchedulerTestState();
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $registry->expects(self::never())->method('resetManager');

        $scheduler = new MaintenanceScheduler(
            [new MaintenanceSchedulerTestTask($holder, 'task.failing', 100, true)],
            $this->createStub(LoggerInterface::class),
            new ClosedEntityManagerReset($registry),
        );
        $scheduler->tick();

        self::assertSame(['task.failing'], $holder->runOrder);
    }

    private function registryFor(MaintenanceSchedulerTestPersistence $persistence): ManagerRegistry
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('isOpen')->willReturnCallback(static fn (): bool => !$persistence->closed);

        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($entityManager);
        $registry->method('resetManager')->willReturnCallback(static function () use ($persistence, $entityManager): EntityManagerInterface {
            if (null !== $persistence->resetFailure) {
                throw $persistence->resetFailure;
            }
            ++$persistence->resets;
            $persistence->closed = false;

            return $entityManager;
        });

        return $registry;
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

final class MaintenanceSchedulerTestPersistence
{
    public bool $closed = false;

    public int $resets = 0;

    public ?RuntimeException $resetFailure = null;
}

/** Closes the EntityManager and fails, like a task whose flush hit a database error. */
final class MaintenanceSchedulerTestClosingTask extends MaintenanceSchedulerTestTask
{
    public function __construct(
        MaintenanceSchedulerTestState $holder,
        private readonly MaintenanceSchedulerTestPersistence $persistence,
        string $name,
        int $order,
    ) {
        parent::__construct($holder, $name, $order, true);
    }

    public function run(): void
    {
        $this->persistence->closed = true;
        parent::run();
    }
}

/** Records whether the EntityManager was open when it ran. */
final class MaintenanceSchedulerTestPersistenceTask extends MaintenanceSchedulerTestTask
{
    public function __construct(
        private readonly MaintenanceSchedulerTestState $state,
        private readonly MaintenanceSchedulerTestPersistence $persistence,
        private readonly string $taskName,
        int $order,
    ) {
        parent::__construct($state, $taskName, $order);
    }

    public function run(): void
    {
        $this->state->runOrder[] = $this->taskName . ($this->persistence->closed ? ':closed' : ':open');
    }
}
