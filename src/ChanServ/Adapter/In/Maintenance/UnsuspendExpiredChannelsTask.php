<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Automatically unsuspends channels whose suspension has expired.
 * Dispatched periodically by the maintenance scheduler.
 *
 * Order 196: After NickServ unsuspend expired (195), before channel purge (200).
 */
final readonly class UnsuspendExpiredChannelsTask implements MaintenanceTaskInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private EventBusInterface $eventDispatcher,
        private LoggerInterface $logger,
        private string $serverName,
        private int $intervalSeconds,
    ) {}

    public function getName(): string
    {
        return 'chanserv.unsuspend_expired_channels';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 196;
    }

    public function run(): void
    {
        $occurredAt = new DateTimeImmutable();
        $expired = $this->channelRepository->iterateExpiredSuspensions($occurredAt);

        foreach ($expired as $channel) {
            $channelName = $channel->getName();
            $channelId = $channel->getId();
            $channelNameLower = $channel->getNameLower();

            $channel->unsuspend();
            $this->channelRepository->save($channel);

            $this->eventDispatcher->dispatch(new ChannelUnsuspendedEvent(
                channelId: $channelId,
                channelName: $channelName,
                channelNameLower: $channelNameLower,
                performedBy: $this->serverName,
                performedByNickId: null,
                performedByIp: '*',
                performedByHost: '*',
                occurredAt: $occurredAt,
            ));

            $this->logger->info(sprintf(
                'Maintenance [%s]: auto-unsuspended channel %s (id %d).',
                $this->getName(),
                $channelName,
                $channelId,
            ));
        }
    }
}
