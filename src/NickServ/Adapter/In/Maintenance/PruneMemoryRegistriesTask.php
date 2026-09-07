<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\InMemoryPrunableInterface;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
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
        private iterable $prunables,
        private LoggerInterface $logger,
        private int $intervalSeconds,
    ) {}

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
