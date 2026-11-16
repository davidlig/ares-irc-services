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

    /** Id of the last nick visited by the current sweep; 0 starts a new sweep. */
    private int $cursor = 0;

    private readonly ClosedEntityManagerRetryBudget $retryBudget;

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
    ) {
        $this->retryBudget = new ClosedEntityManagerRetryBudget($this->logger, $this->getName());
    }

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
     * nick, so the next cycle retries it; after {@see ClosedEntityManagerRetryBudget::MAX_ATTEMPTS} such
     * failures in a row while the database answers, the nick is skipped until the next sweep, so it
     * cannot block later rows; in later sweeps it gets one attempt before it is skipped again, and
     * a database outage never skips it.
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

                $failedOnItsOwn = false;
                try {
                    $this->dropService->dropNick($nick, $now, 'inactivity', null);
                } catch (Throwable $e) {
                    $failedOnItsOwn = $this->recordFailure($id, $nickname, $e);
                }

                // Advanced only after a row-specific outcome: a closed EntityManager rethrows above,
                // so the next cycle retries this nick instead of skipping it.
                $this->cursor = max($this->cursor, $id);
                if ($failedOnItsOwn) {
                    $this->retryBudget->rowFailedOnItsOwn($id);
                } else {
                    $this->retryBudget->rowSucceeded($id);
                }
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
     * @return bool true when the nick still exists, i.e. the drop failed on its own
     *
     * @throws Throwable when the EntityManager was closed by the failure
     */
    private function recordFailure(int $id, string $nickname, Throwable $e): bool
    {
        $this->retryBudget->rethrowWhenClosed(
            $id,
            $this->entityManager,
            $e,
            sprintf('failed to drop inactive nick %s (id %d)', $nickname, $id),
            function () use ($id): void {
                $this->cursor = max($this->cursor, $id);
            },
        );

        if (null === $this->nickRepository->findById($id)) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: dropped inactive nick %s (id %d), but a post-drop step failed.',
                $this->getName(),
                $nickname,
                $id,
            ), ['exception' => $e]);

            return false;
        }

        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to drop inactive nick %s (id %d); skipping it until the next sweep.',
            $this->getName(),
            $nickname,
            $id,
        ), ['exception' => $e]);

        return true;
    }
}
