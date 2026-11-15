<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Domain\Entity\RegisteredNick;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function count;
use function max;
use function sprintf;

final readonly class PurgePendingDeletionNicknamesTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    private const int DEFAULT_MAX_BATCHES = 1;

    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickDropService $dropService,
        private LoggerInterface $logger,
        private Clock $clock,
        private int $intervalSeconds,
        private int $dropGraceDays,
        private ?EntityManagerInterface $entityManager = null,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
    ) {}

    public function getName(): string
    {
        return 'nickserv.purge_pending_deletion_nicknames';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 210;
    }

    public function run(): void
    {
        $now = $this->clock->now();
        $threshold = $now->modify(sprintf('-%d days', max(0, $this->dropGraceDays)));
        $batches = 0;
        /** @var array<int, true> $failed nick ids that failed earlier in this cycle */
        $failed = [];

        do {
            // Failed rows stay in the result set; widen the page so they never take a slot.
            $limit = $this->batchSize + count($failed);
            $batch = $this->nickRepository->findPendingDeletionBefore($threshold, $limit);
            if ([] === $batch) {
                break;
            }

            foreach ($batch as $nick) {
                // @phpstan-ignore instanceof.alwaysTrue
                if (!$nick instanceof RegisteredNick || isset($failed[$nick->getId()])) {
                    continue;
                }

                try {
                    $this->dropService->hardDropNick($nick, $now, 'manual-grace-expired', null);
                } catch (Throwable $e) {
                    if (!$this->recordFailure($nick, $e)) {
                        $failed[$nick->getId()] = true;
                    }
                }
            }

            $this->entityManager?->clear();
            ++$batches;
        } while (count($batch) === $limit && $batches < $this->maxBatchesPerCycle);
    }

    /**
     * Logs a failed drop accurately and tells whether the row is gone.
     *
     * A closed EntityManager makes every later row fail too, so the batch stops and the task fails.
     *
     * @throws Throwable when the EntityManager was closed by the failure
     */
    private function recordFailure(RegisteredNick $nick, Throwable $e): bool
    {
        if (null !== $this->entityManager && !$this->entityManager->isOpen()) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to drop pending-deletion nick %s (id %d); the EntityManager is closed, stopping this batch.',
                $this->getName(),
                $nick->getNickname(),
                $nick->getId(),
            ), ['exception' => $e]);

            throw $e;
        }

        if (null === $this->nickRepository->findById($nick->getId())) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: dropped pending-deletion nick %s (id %d), but a post-drop step failed.',
                $this->getName(),
                $nick->getNickname(),
                $nick->getId(),
            ), ['exception' => $e]);

            return true;
        }

        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to drop pending-deletion nick %s (id %d); skipping it until the next cycle.',
            $this->getName(),
            $nick->getNickname(),
            $nick->getId(),
        ), ['exception' => $e]);

        return false;
    }
}
