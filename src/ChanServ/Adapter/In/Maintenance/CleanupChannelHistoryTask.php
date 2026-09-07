<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

use function sprintf;

final readonly class CleanupChannelHistoryTask implements MaintenanceTaskInterface
{
    public function __construct(
        private ChannelHistoryRepositoryInterface $historyRepository,
        private LoggerInterface $logger,
        private int $intervalSeconds,
        private int $retentionDays,
    ) {}

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

        $threshold = new DateTimeImmutable()->modify(sprintf('-%d days', $this->retentionDays));
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
