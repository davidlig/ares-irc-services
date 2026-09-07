<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

use function max;
use function sprintf;

final readonly class PurgePendingDeletionChannelsTask implements MaintenanceTaskInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanDropService $dropService,
        private LoggerInterface $logger,
        private int $intervalSeconds,
        private int $dropGraceDays,
    ) {}

    public function getName(): string
    {
        return 'chanserv.purge_pending_deletion_channels';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 310;
    }

    public function run(): void
    {
        $threshold = new DateTimeImmutable()->modify(sprintf('-%d days', max(0, $this->dropGraceDays)));
        $expired = $this->channelRepository->findPendingDeletionBefore($threshold);

        foreach ($expired as $channel) {
            $this->dropService->hardDropChannel($channel, 'manual-grace-expired', null);
            $this->logger->info(sprintf(
                'Maintenance [%s]: permanently deleted channel %s (id %d) after DROP grace period.',
                $this->getName(),
                $channel->getName(),
                $channel->getId(),
            ));
        }
    }
}
