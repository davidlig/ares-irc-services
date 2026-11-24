<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Maintenance;

use App\Irc\Adapter\Out\Persistence\ClosedEntityManagerReset;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\Maintenance\PrerequisiteAwareMaintenanceTaskInterface;
use Psr\Log\LoggerInterface;

use function count;
use function hrtime;
use function iterator_to_array;
use function round;
use function usort;

/**
 * Runs registered MaintenanceTaskInterface instances from the IRC event loop.
 *
 * Call tick() on every loop iteration where no IRC data was available.
 * Each task tracks its own last-run timestamp and only executes once its
 * configured interval has elapsed.
 *
 * Tasks execute sequentially in ascending getOrder() to respect cross-service
 * dependencies (e.g. orphan channels must be handled before nicks are deleted).
 * A task that throws is logged and retried on the next tick; the remaining tasks
 * of the cycle still run, so one persistent failure cannot starve the others.
 *
 * A task implementing PrerequisiteAwareMaintenanceTaskInterface is skipped for the
 * cycle (logged as a warning, not marked as run, so it is retried on the next tick)
 * when one of its declared prerequisites failed earlier in the cycle or was itself
 * skipped for that reason. A prerequisite that was not due this cycle does not block.
 *
 * A database error can close the Doctrine EntityManager, which would make every later
 * Doctrine-backed task fail until restart. The scheduler therefore resets a closed
 * EntityManager (logged as a warning) at the start of each cycle and after each failed
 * task (see ClosedEntityManagerReset, which IRCClient also runs before every incoming line).
 */
final class MaintenanceScheduler
{
    /** @var list<MaintenanceTaskInterface> Sorted by getOrder() ascending. */
    private readonly array $sortedTasks;

    /** @var array<string, float> task name → monotonic timestamp of last run. */
    private array $lastRun = [];

    /**
     * @param iterable<MaintenanceTaskInterface> $tasks
     */
    public function __construct(
        iterable $tasks,
        private readonly LoggerInterface $logger,
        private readonly ClosedEntityManagerReset $entityManagerReset = new ClosedEntityManagerReset(),
    ) {
        $sorted = iterator_to_array($tasks, false);
        usort($sorted, static fn (MaintenanceTaskInterface $a, MaintenanceTaskInterface $b) => $a->getOrder() <=> $b->getOrder());
        $this->sortedTasks = $sorted;
    }

    public function tick(): void
    {
        $now = hrtime(true) / 1_000_000_000.0;
        $cycleStart = hrtime(true);
        $tasksRun = [];
        $tasksFailed = [];
        $tasksSkipped = [];
        /** @var array<string, true> $blocked tasks that failed or were skipped in this cycle */
        $blocked = [];

        $this->logger->info('Maintenance cycle started');
        $this->resetClosedEntityManager(null);

        foreach ($this->sortedTasks as $task) {
            $last = $this->lastRun[$task->getName()] ?? 0.0;

            if (0.0 !== $last && ($now - $last) < $task->getIntervalSeconds()) {
                continue;
            }

            $failedPrerequisite = $this->failedPrerequisite($task, $blocked);
            if (null !== $failedPrerequisite) {
                $blocked[$task->getName()] = true;
                $tasksSkipped[] = $task->getName();
                $this->logger->warning('Maintenance task skipped: a prerequisite failed in this cycle', [
                    'task' => $task->getName(),
                    'prerequisite' => $failedPrerequisite,
                ]);

                continue;
            }

            try {
                $taskStart = hrtime(true);
                $task->run();
                $this->lastRun[$task->getName()] = $now;
                $durationMs = (hrtime(true) - $taskStart) / 1_000_000.0;
                $tasksRun[] = $task->getName();

                $this->logger->info('Maintenance task executed', [
                    'task' => $task->getName(),
                    'duration_ms' => round($durationMs, 2),
                ]);
            } catch (\Throwable $e) {
                $blocked[$task->getName()] = true;
                $tasksFailed[] = $task->getName();
                $this->logger->error('Maintenance task failed', [
                    'task' => $task->getName(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
                $this->resetClosedEntityManager($task->getName());
            }
        }

        $cycleDurationMs = (hrtime(true) - $cycleStart) / 1_000_000.0;
        $this->logger->info('Maintenance cycle completed', [
            'tasks_run' => $tasksRun,
            'tasks_count' => count($tasksRun),
            'tasks_failed' => $tasksFailed,
            'tasks_skipped' => $tasksSkipped,
            'cycle_duration_ms' => round($cycleDurationMs, 2),
        ]);
    }

    private function resetClosedEntityManager(?string $afterTask): void
    {
        $this->entityManagerReset->resetIfClosed($this->logger, 'Maintenance', ['after_task' => $afterTask]);
    }

    /**
     * @param array<string, true> $blocked
     */
    private function failedPrerequisite(MaintenanceTaskInterface $task, array $blocked): ?string
    {
        if (!$task instanceof PrerequisiteAwareMaintenanceTaskInterface) {
            return null;
        }

        foreach ($task->getPrerequisiteTaskNames() as $prerequisite) {
            if (isset($blocked[$prerequisite])) {
                return $prerequisite;
            }
        }

        return null;
    }
}
