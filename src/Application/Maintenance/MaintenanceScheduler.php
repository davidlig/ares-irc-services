<?php

declare(strict_types=1);

namespace App\Application\Maintenance;

use Psr\Log\LoggerInterface;
use Throwable;

use function sprintf;

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

        foreach ($this->sortedTasks as $task) {
            $last = $this->lastRun[$task->getName()] ?? 0.0;

            if (($now - $last) < $task->getIntervalSeconds()) {
                continue;
            }

            try {
                $task->run();
                $this->lastRun[$task->getName()] = $now;
            } catch (Throwable $e) {
                $this->logger->error(sprintf(
                    'Maintenance task [%s] failed; cycle aborted: %s',
                    $task->getName(),
                    $e->getMessage(),
                ), ['exception' => $e]);

                return;
            }
        }
    }
}
