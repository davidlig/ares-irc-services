<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Application\PublishedEvent\GlineRemovedEvent;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function sprintf;

/**
 * Removes expired G-lines in batches ordered by expiry, then id. A G-line whose
 * network removal (the GlineRemovedEvent listeners) keeps failing is logged and left
 * for the next cycle; the keyset cursor moves past it so it never blocks the remaining
 * rows or the later maintenance tasks. The debug-channel log is best-effort: its failure
 * is logged on its own and never keeps a G-line that was removed from the network.
 *
 * A cycle handles at most maxBatchesPerCycle batches, so a mass expiry cannot block the event
 * loop; the keyset cursor is kept between cycles, so the next cycle continues where this one
 * stopped, and a short page ends the sweep (the next cycle starts again from the beginning,
 * which also retries the skipped rows). The cursor only advances once a batch was deleted.
 */
final class PurgeExpiredGlinesTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    public const int DEFAULT_MAX_BATCHES = 10;

    /** Last G-line visited by the current sweep; null starts a new sweep. */
    private ?GlineEntry $cursor = null;

    public function __construct(
        private readonly GlineRepository $glineRepository,
        private readonly ServiceDebugNotifierInterface $debugNotifier,
        private readonly EventBusInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly string $serverName,
        private readonly int $intervalSeconds,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
    ) {}

    public function getName(): string
    {
        return 'operserv.purge_expired_glines';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 360;
    }

    public function run(): void
    {
        $occurredAt = new DateTimeImmutable();
        $batches = 0;

        do {
            $after = $this->cursor;
            $expired = $this->glineRepository->findExpiredAt($occurredAt, $after, $this->batchSize);
            $ids = [];
            foreach ($expired as $gline) {
                $glineId = $gline->id;
                if (null === $glineId) {
                    throw new LogicException('Persisted GLINE entry must have an identifier.');
                }

                $after = $gline;
                if ($this->remove($gline->mask, $glineId, $occurredAt)) {
                    $ids[] = $glineId;
                }
            }

            if ([] !== $ids) {
                $this->glineRepository->deleteByIds($ids);
            }

            ++$batches;
            if (count($expired) < $this->batchSize) {
                $this->cursor = null;

                return;
            }
            $this->cursor = $after;
        } while ($batches < $this->maxBatchesPerCycle);
    }

    private function remove(string $mask, int $glineId, DateTimeImmutable $occurredAt): bool
    {
        try {
            $this->eventDispatcher->dispatch(new GlineRemovedEvent(
                glineId: $glineId,
                mask: $mask,
                removedBy: $this->serverName,
                cause: 'expired',
                occurredAt: $occurredAt,
            ));
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to remove expired GLINE %s (id %d); skipping it until the next cycle.',
                $this->getName(),
                $mask,
                $glineId,
            ), ['exception' => $e]);

            return false;
        }

        try {
            $this->debugNotifier->log(
                operator: $this->serverName,
                command: 'GLINE DEL',
                target: $mask,
                reason: 'expired',
            );
        } catch (Throwable $e) {
            $this->logger->warning(sprintf(
                'Maintenance [%s]: could not log the removal of expired GLINE %s (id %d) to the debug channel.',
                $this->getName(),
                $mask,
                $glineId,
            ), ['exception' => $e]);
        }

        $this->logger->info(sprintf(
            'Maintenance [%s]: removed expired GLINE %s (id %d).',
            $this->getName(),
            $mask,
            $glineId,
        ));

        return true;
    }
}
