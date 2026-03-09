<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Removes registered channels that have been inactive for more than the configured days.
 * Dispatches ChannelDropEvent before each deletion so other services (MemoServ) can clean up.
 *
 * Order 300: ChanServ channel expiry range.
 */
final readonly class PurgeInactiveChannelsTask implements MaintenanceTaskInterface
{
    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds,
        private readonly int $inactivityExpiryDays,
    ) {
    }

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

        $threshold = (new DateTimeImmutable())->modify(sprintf('-%d days', $this->inactivityExpiryDays));
        $inactive = $this->channelRepository->findRegisteredInactiveSince($threshold);

        foreach ($inactive as $channel) {
            if (!$channel instanceof RegisteredChannel) {
                continue;
            }

            $channelId = $channel->getId();
            $channelName = $channel->getName();
            $channelNameLower = $channel->getNameLower();
            $lastActivity = $channel->getLastUsedAt() ?? $channel->getCreatedAt();
            $lastActivityStr = null !== $lastActivity ? $lastActivity->format('Y-m-d H:i:s') : 'n/a';

            $this->eventDispatcher->dispatch(new ChannelDropEvent(
                $channelId,
                $channelName,
                $channelNameLower,
                'inactivity',
            ));

            $this->channelRepository->delete($channel);

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
