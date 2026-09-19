<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Removes expired AKICK entries from channels.
 * Dispatched periodically by the maintenance scheduler.
 *
 * Order 350: ChanServ AKICK cleanup (after channel purge).
 */
final readonly class PurgeExpiredAkickTask implements MaintenanceTaskInterface
{
    public function __construct(
        private ChannelAkickRepositoryInterface $akickRepository,
        private ServiceDebugNotifierInterface $debugNotifier,
        private LoggerInterface $logger,
        private string $serverName,
        private int $intervalSeconds,
    ) {}

    public function getName(): string
    {
        return 'chanserv.purge_expired_akick';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 350;
    }

    public function run(): void
    {
        $expired = $this->akickRepository->findExpired();

        foreach ($expired as $akick) {
            $channelId = $akick->getChannelId();
            $mask = $akick->getMask();
            $akickId = $akick->getId();

            $this->akickRepository->remove($akick);

            $this->debugNotifier->log(
                operator: $this->serverName,
                command: 'AKICK DEL',
                target: $mask,
                reason: 'expired',
            );

            $this->logger->info(sprintf(
                'Maintenance [%s]: removed expired AKICK %s (id %d) from channel %d.',
                $this->getName(),
                $mask,
                $akickId,
                $channelId,
            ));
        }
    }
}
