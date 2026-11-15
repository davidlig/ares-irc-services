<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use Psr\Log\LoggerInterface;

use function count;
use function sprintf;

/**
 * Removes expired AKICK entries from channels in batches ordered by expiry, then id.
 * Dispatched periodically by the maintenance scheduler.
 *
 * Every batch is deleted before the next one is read, so each read starts from the first expired
 * entry and no cursor is needed. Each cycle deletes at most $maxBatchesPerCycle batches; the rest
 * is deleted in later cycles. A failed delete stops the cycle, and a batch that deletes nothing
 * ends it too, so the same entries are never read again within one cycle.
 *
 * Order 350: ChanServ AKICK cleanup (after channel purge).
 */
final readonly class PurgeExpiredAkickTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    public const int DEFAULT_MAX_BATCHES_PER_CYCLE = 10;

    public function __construct(
        private ChannelAkickRepositoryInterface $akickRepository,
        private LoggerInterface $logger,
        private int $intervalSeconds,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES_PER_CYCLE,
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
        for ($batches = 0; $batches < $this->maxBatchesPerCycle; ++$batches) {
            $expired = $this->akickRepository->findExpired(null, $this->batchSize);
            if ([] === $expired) {
                return;
            }

            $ids = [];
            foreach ($expired as $akick) {
                $akickId = $akick->getId();
                $ids[] = $akickId;

                $this->logger->info(sprintf(
                    'Maintenance [%s]: removed expired AKICK %s (id %d) from channel %d.',
                    $this->getName(),
                    $akick->getMask(),
                    $akickId,
                    $akick->getChannelId(),
                ));
            }

            $deleted = $this->akickRepository->deleteByIds($ids);

            if (count($expired) < $this->batchSize || 0 === $deleted) {
                return;
            }
        }
    }
}
