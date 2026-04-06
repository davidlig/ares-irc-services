<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
use DateTimeImmutable;
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
        private readonly int $intervalSeconds,
        private readonly int $retentionDays,
    ) {
    }

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

        $threshold = (new DateTimeImmutable())->modify(sprintf('-%d days', $this->retentionDays));
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
