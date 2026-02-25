<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance;

use App\Application\Maintenance\InMemoryPrunableInterface;
use App\Application\Maintenance\MaintenanceTaskInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Runs prune() on all registered InMemoryPrunableInterface instances.
 * Logs a summary only when at least one entry was removed.
 */
final readonly class PruneMemoryRegistriesTask implements MaintenanceTaskInterface
{
    /**
     * @param iterable<InMemoryPrunableInterface> $prunables
     */
    public function __construct(
        private readonly iterable $prunables,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds,
    ) {
    }

    public function getName(): string
    {
        return 'nickserv.prune_memory_registries';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 110;
    }

    public function run(): void
    {
        $total = 0;

        foreach ($this->prunables as $prunable) {
            $total += $prunable->prune();
        }

        if ($total > 0) {
            $this->logger->info(
                sprintf('Maintenance [%s]: pruned %d stale in-memory entr(y/ies).', $this->getName(), $total),
            );
        }
    }
}
