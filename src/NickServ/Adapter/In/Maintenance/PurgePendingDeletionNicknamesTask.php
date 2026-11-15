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

final class PurgePendingDeletionNicknamesTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    private const int DEFAULT_MAX_BATCHES = 1;

    /** Id of the last nick visited by the current sweep; 0 starts a new sweep. */
    private int $cursor = 0;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickDropService $dropService,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
        private readonly int $intervalSeconds,
        private readonly int $dropGraceDays,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
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

    /**
     * Each cycle continues the sweep after the last visited id, so nicks that keep failing
     * are skipped until the next sweep instead of taking every page.
     */
    public function run(): void
    {
        $now = $this->clock->now();
        $threshold = $now->modify(sprintf('-%d days', max(0, $this->dropGraceDays)));
        $batches = 0;

        do {
            $batch = $this->nickRepository->findPendingDeletionBefore($threshold, $this->batchSize, $this->cursor);
            if ([] === $batch) {
                $this->cursor = 0;

                return;
            }

            foreach ($batch as $nick) {
                // @phpstan-ignore instanceof.alwaysTrue
                if (!$nick instanceof RegisteredNick) {
                    continue;
                }

                // Doctrine unsets the id once the drop is flushed; keep what the failure log needs.
                $id = $nick->getId();
                $nickname = $nick->getNickname();

                try {
                    $this->dropService->hardDropNick($nick, $now, 'manual-grace-expired', null);
                } catch (Throwable $e) {
                    $this->recordFailure($id, $nickname, $e);
                }

                // Advanced only after a row-specific outcome: a closed EntityManager rethrows above,
                // so the next cycle retries this nick instead of skipping it.
                $this->cursor = max($this->cursor, $id);
            }

            $this->entityManager?->clear();
            ++$batches;

            if (count($batch) < $this->batchSize) {
                $this->cursor = 0;

                return;
            }
        } while ($batches < $this->maxBatchesPerCycle);
    }

    /**
     * Logs a failed drop accurately.
     *
     * A closed EntityManager makes every later row fail too, so the batch stops and the task fails.
     *
     * @throws Throwable when the EntityManager was closed by the failure
     */
    private function recordFailure(int $id, string $nickname, Throwable $e): void
    {
        if (null !== $this->entityManager && !$this->entityManager->isOpen()) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to drop pending-deletion nick %s (id %d); the EntityManager is closed, stopping this batch.',
                $this->getName(),
                $nickname,
                $id,
            ), ['exception' => $e]);

            throw $e;
        }

        if (null === $this->nickRepository->findById($id)) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: dropped pending-deletion nick %s (id %d), but a post-drop step failed.',
                $this->getName(),
                $nickname,
                $id,
            ), ['exception' => $e]);

            return;
        }

        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to drop pending-deletion nick %s (id %d); skipping it until the next sweep.',
            $this->getName(),
            $nickname,
            $id,
        ), ['exception' => $e]);
    }
}
