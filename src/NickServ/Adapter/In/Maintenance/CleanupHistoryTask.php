<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Removes nickname history entries older than the configured retention period.
 *
 * Order 250: NickServ history cleanup (after account expiry at 200).
 */
final readonly class CleanupHistoryTask implements MaintenanceTaskInterface
{
    public function __construct(
        private NickHistoryRepositoryInterface $historyRepository,
        private LoggerInterface $logger,
        private Clock $clock,
        private int $intervalSeconds,
        private int $retentionDays,
    ) {}

    public function getName(): string
    {
        return 'nickserv.cleanup_history';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 250;
    }

    public function run(): void
    {
        if ($this->retentionDays <= 0) {
            return;
        }

        $threshold = $this->clock->now()->modify(sprintf('-%d days', $this->retentionDays));
        $deleted = $this->historyRepository->deleteOlderThan($threshold);

        if ($deleted > 0) {
            $this->logger->info(sprintf(
                'Cleaned up %d history entries older than %d days.',
                $deleted,
                $this->retentionDays,
            ));
        }
    }
}
