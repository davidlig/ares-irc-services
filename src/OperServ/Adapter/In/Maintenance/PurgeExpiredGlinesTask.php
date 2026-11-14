<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
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
 * Removes expired G-lines in id-ordered batches. A G-line whose removal keeps
 * failing is logged and left for the next cycle; the keyset cursor moves past it
 * so it never blocks the remaining rows or the later maintenance tasks.
 */
final readonly class PurgeExpiredGlinesTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private GlineRepository $glineRepository,
        private ServiceDebugNotifierInterface $debugNotifier,
        private EventBusInterface $eventDispatcher,
        private LoggerInterface $logger,
        private string $serverName,
        private int $intervalSeconds,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
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
        $after = null;

        do {
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
        } while (count($expired) === $this->batchSize);
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

            $this->debugNotifier->log(
                operator: $this->serverName,
                command: 'GLINE DEL',
                target: $mask,
                reason: 'expired',
            );
        } catch (Throwable $e) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to remove expired GLINE %s (id %d); skipping it until the next cycle.',
                $this->getName(),
                $mask,
                $glineId,
            ), ['exception' => $e]);

            return false;
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
