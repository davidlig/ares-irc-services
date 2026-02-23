<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use Psr\Log\LoggerInterface;
use Throwable;

use function count;

/**
 * Runs registered MaintenanceTaskInterface instances from the IRC event loop.
 *
 * Call tick() on every loop iteration where no IRC data was available.
 * Each task tracks its own last-run timestamp and only executes once its
 * configured interval has elapsed.
 *
 * Tasks execute sequentially in ascending getOrder() to respect cross-service
 * dependencies (e.g. orphan channels must be handled before nicks are deleted).
 * If any task throws, the cycle stops immediately to avoid inconsistent state.
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

        $this->logger->info('Maintenance cycle started');

        foreach ($this->sortedTasks as $task) {
            $last = $this->lastRun[$task->getName()] ?? 0.0;

            if (($now - $last) < $task->getIntervalSeconds()) {
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
                $this->logger->error('Maintenance cycle aborted', [
                    'task' => $task->getName(),
                    'error' => $e->getMessage(),
                    'tasks_completed' => $tasksRun,
                    'exception' => $e,
                ]);

                return;
            }
        }

        $cycleDurationMs = (hrtime(true) - $cycleStart) / 1_000_000.0;
        $this->logger->info('Maintenance cycle completed', [
            'tasks_run' => $tasksRun,
            'tasks_count' => count($tasksRun),
            'cycle_duration_ms' => round($cycleDurationMs, 2),
        ]);
    }
}
