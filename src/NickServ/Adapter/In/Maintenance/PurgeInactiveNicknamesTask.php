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

/**
 * Removes REGISTERED nicknames that have been inactive for more than the configured days.
 * Uses NickDropService for proper cleanup (event dispatch, force rename if online, etc).
 *
 * Order 200: NickServ account expiry range.
 */
final class PurgeInactiveNicknamesTask implements MaintenanceTaskInterface
{
    public const int DEFAULT_BATCH_SIZE = 100;

    private const int DEFAULT_MAX_BATCHES = 1;

    /** Consecutive closed-EntityManager failures after which a row is skipped until the next sweep. */
    private const int MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS = 3;

    /** Id of the last nick visited by the current sweep; 0 starts a new sweep. */
    private int $cursor = 0;

    /** Id of the row whose last attempt closed the EntityManager; 0 when the last row had its own outcome. */
    private int $closedFailureId = 0;

    private int $closedFailureAttempts = 0;

    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickDropService $dropService,
        private readonly LoggerInterface $logger,
        private readonly Clock $clock,
        private readonly int $intervalSeconds,
        private readonly int $inactivityExpiryDays,
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly int $batchSize = self::DEFAULT_BATCH_SIZE,
        private readonly int $maxBatchesPerCycle = self::DEFAULT_MAX_BATCHES,
    ) {}

    public function getName(): string
    {
        return 'nickserv.purge_inactive_nicknames';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 200;
    }

    /**
     * Each cycle continues the sweep after the last visited id, so nicks that keep failing
     * are skipped until the next sweep instead of taking every page.
     *
     * A failure that closes the EntityManager stops the batch with the cursor kept before the
     * nick, so the next cycle retries it; after {@see self::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS} such
     * failures in a row the nick is skipped until the next sweep, so it cannot block later rows.
     */
    public function run(): void
    {
        if ($this->inactivityExpiryDays <= 0) {
            return;
        }

        $now = $this->clock->now();
        $threshold = $now->modify(sprintf('-%d days', $this->inactivityExpiryDays));
        $batches = 0;

        do {
            $batch = $this->nickRepository->findRegisteredInactiveSince($threshold, $this->batchSize, $this->cursor);
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
                    $this->dropService->dropNick($nick, $now, 'inactivity', null);
                } catch (Throwable $e) {
                    $this->recordFailure($id, $nickname, $e);
                }

                // Advanced only after a row-specific outcome: a closed EntityManager rethrows above,
                // so the next cycle retries this nick instead of skipping it.
                $this->cursor = max($this->cursor, $id);
                $this->closedFailureId = 0;
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
     * A closed EntityManager makes every later row fail too, so the batch stops and the task fails;
     * the nick is retried next cycle until it has closed the EntityManager too many times in a row.
     *
     * @throws Throwable when the EntityManager was closed by the failure
     */
    private function recordFailure(int $id, string $nickname, Throwable $e): void
    {
        if (null !== $this->entityManager && !$this->entityManager->isOpen()) {
            if ($this->giveUpAfterClosedEntityManager($id)) {
                $this->logger->error(sprintf(
                    'Maintenance [%s]: failed to drop inactive nick %s (id %d) with the EntityManager closed %d times in a row; skipping it until the next sweep and stopping this batch.',
                    $this->getName(),
                    $nickname,
                    $id,
                    self::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS,
                ), ['exception' => $e]);

                throw $e;
            }

            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to drop inactive nick %s (id %d); the EntityManager is closed, stopping this batch.',
                $this->getName(),
                $nickname,
                $id,
            ), ['exception' => $e]);

            throw $e;
        }

        if (null === $this->nickRepository->findById($id)) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: dropped inactive nick %s (id %d), but a post-drop step failed.',
                $this->getName(),
                $nickname,
                $id,
            ), ['exception' => $e]);

            return;
        }

        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to drop inactive nick %s (id %d); skipping it until the next sweep.',
            $this->getName(),
            $nickname,
            $id,
        ), ['exception' => $e]);
    }

    /**
     * Counts a closed-EntityManager failure of the row and, once it reached the limit in a row,
     * moves the cursor past it so the next cycle continues with the later rows.
     */
    private function giveUpAfterClosedEntityManager(int $id): bool
    {
        if ($this->closedFailureId !== $id) {
            $this->closedFailureId = $id;
            $this->closedFailureAttempts = 0;
        }

        if (++$this->closedFailureAttempts < self::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS) {
            return false;
        }

        $this->closedFailureId = 0;
        $this->cursor = max($this->cursor, $id);

        return true;
    }
}
