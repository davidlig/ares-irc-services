<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Domain\ChanServ\Repository\ChannelHistoryRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

use function sprintf;

final readonly class CleanupChannelHistoryTask implements MaintenanceTaskInterface
{
    public function __construct(
        private ChannelHistoryRepositoryInterface $historyRepository,
        private LoggerInterface $logger,
        private readonly int $intervalSeconds,
        private readonly int $retentionDays,
    ) {
    }

    public function getName(): string
    {
        return 'chanserv.cleanup_channel_history';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 260;
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
                'Cleaned up %d channel history entries older than %d days.',
                $deleted,
                $this->retentionDays,
            ));
        }
    }
}
