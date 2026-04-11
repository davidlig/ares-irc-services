<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Maintenance;

use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
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
        private ChannelSuspensionService $suspensionService,
        private LoggerInterface $logger,
        private readonly int $intervalSeconds,
    ) {
    }

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
        $expired = $this->channelRepository->findExpiredSuspensions();

        foreach ($expired as $channel) {
            $channelName = $channel->getName();
            $channelId = $channel->getId();

            $channel->unsuspend();
            $this->channelRepository->save($channel);

            $this->suspensionService->liftSuspension($channel);

            $this->logger->info(sprintf(
                'Maintenance [%s]: auto-unsuspended channel %s (id %d).',
                $this->getName(),
                $channelName,
                $channelId,
            ));
        }
    }
}
