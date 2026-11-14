<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use Psr\Log\LoggerInterface;
use Throwable;

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

        $this->logger->info('Maintenance cycle started');

        foreach ($this->sortedTasks as $task) {
            $last = $this->lastRun[$task->getName()] ?? 0.0;

            if (0.0 !== $last && ($now - $last) < $task->getIntervalSeconds()) {
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
            } catch (Throwable $e) {
                $tasksFailed[] = $task->getName();
                $this->logger->error('Maintenance task failed', [
                    'task' => $task->getName(),
                    'error' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        $cycleDurationMs = (hrtime(true) - $cycleStart) / 1_000_000.0;
        $this->logger->info('Maintenance cycle completed', [
            'tasks_run' => $tasksRun,
            'tasks_count' => count($tasksRun),
            'tasks_failed' => $tasksFailed,
            'cycle_duration_ms' => round($cycleDurationMs, 2),
        ]);
    }
}
