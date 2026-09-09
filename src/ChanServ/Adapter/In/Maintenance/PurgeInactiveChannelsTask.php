<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Removes registered channels that have been inactive for more than the configured days.
 * Delegates hard deletion so all dependent data is cleaned up atomically.
 *
 * Order 300: ChanServ channel expiry range.
 */
final readonly class PurgeInactiveChannelsTask implements MaintenanceTaskInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanDropService $dropService,
        private LoggerInterface $logger,
        private int $intervalSeconds,
        private int $inactivityExpiryDays,
    ) {}

    public function getName(): string
    {
        return 'chanserv.purge_inactive_channels';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 300;
    }

    public function run(): void
    {
        if ($this->inactivityExpiryDays <= 0) {
            return;
        }

        $now = new DateTimeImmutable();
        $threshold = $now->modify(sprintf('-%d days', $this->inactivityExpiryDays));
        $inactive = $this->channelRepository->findRegisteredInactiveSince($threshold);

        foreach ($inactive as $channel) {
            $channelId = $channel->getId();
            $channelName = $channel->getName();
            $lastActivity = $channel->getLastUsedAt() ?? $channel->getCreatedAt();
            $lastActivityStr = $lastActivity->format('Y-m-d H:i:s');

            $this->dropService->hardDropChannel($channel, $now, 'inactivity');

            $this->logger->info(
                sprintf(
                    'Maintenance [%s]: deleted channel %s (id %d) due to inactivity (last activity: %s).',
                    $this->getName(),
                    $channelName,
                    $channelId,
                    $lastActivityStr,
                ),
            );
        }
    }
}
