<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use Psr\Log\LoggerInterface;

use function count;
use function sprintf;

/**
 * Removes expired AKICK entries from channels in id-ordered batches.
 * Dispatched periodically by the maintenance scheduler.
 *
 * Order 350: ChanServ AKICK cleanup (after channel purge).
 */
final readonly class PurgeExpiredAkickTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private ChannelAkickRepositoryInterface $akickRepository,
        private LoggerInterface $logger,
        private int $intervalSeconds,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
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
        $after = null;

        do {
            $expired = $this->akickRepository->findExpired($after, $this->batchSize);
            $ids = [];
            foreach ($expired as $akick) {
                $akickId = $akick->getId();
                $after = $akick;
                $ids[] = $akickId;

                $this->logger->info(sprintf(
                    'Maintenance [%s]: removed expired AKICK %s (id %d) from channel %d.',
                    $this->getName(),
                    $akick->getMask(),
                    $akickId,
                    $akick->getChannelId(),
                ));
            }

            if ([] !== $ids) {
                $this->akickRepository->deleteByIds($ids);
            }
        } while (count($expired) === $this->batchSize);
    }
}
