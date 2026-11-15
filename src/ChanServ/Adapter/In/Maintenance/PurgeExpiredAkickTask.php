<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use Psr\Log\LoggerInterface;

use function count;
use function sprintf;

/**
 * Removes expired AKICK entries from channels in batches ordered by expiry, then id.
 * Dispatched periodically by the maintenance scheduler.
 *
 * Each cycle deletes at most $maxBatchesPerCycle batches; the next cycle continues after the
 * last deleted entry (an in-memory expiry/id cursor), and a short page ends the sweep.
 *
 * Order 350: ChanServ AKICK cleanup (after channel purge).
 */
final class PurgeExpiredAkickTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    public const int DEFAULT_MAX_BATCHES_PER_CYCLE = 10;

    /** Last deleted entry of the current sweep; null starts a new sweep. */
    private ?ChannelAkick $cursor = null;

    public function __construct(
        private readonly ChannelAkickRepositoryInterface $akickRepository,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES_PER_CYCLE,
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
            $expired = $this->akickRepository->findExpired($this->cursor, $this->batchSize);
            $ids = [];
            $last = null;
            foreach ($expired as $akick) {
                $akickId = $akick->getId();
                $last = $akick;
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

            // Moved only once the batch is deleted, so a failed delete is retried next cycle.
            $this->cursor = $last;

            if (count($expired) < $this->batchSize) {
                $this->cursor = null;

                return;
            }
        }
    }
}
