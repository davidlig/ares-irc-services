<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_key_first;
use function count;
use function sprintf;

/**
 * Bounds how often one maintenance row may close the EntityManager before it is skipped.
 *
 * Doctrine closes the EntityManager on any failure inside a flush, so a row that always fails
 * would be retried forever. A failure counts against the row only while the database still
 * answers: during an outage every row closes the EntityManager, so the failure says nothing
 * about the row and must neither count nor skip it.
 *
 * A row that used up its attempts is remembered across sweeps: in a later sweep it gets a single
 * attempt and, if it closes the EntityManager again, it is skipped at once instead of blocking
 * the task for another full budget.
 */
final class ClosedEntityManagerRetryBudget
{
    /** Consecutive closed-EntityManager failures after which a row is skipped until the next sweep. */
    public const int MAX_ATTEMPTS = 3;

    /** Rows remembered as exhausted; the oldest is forgotten first, so memory stays bounded. */
    public const int MAX_REMEMBERED_ROWS = 1000;

    /** Id of the row whose last counted attempt closed the EntityManager; 0 when none. */
    private int $rowId = 0;

    private int $attempts = 0;

    /** @var array<int, true> ids of rows that used up their attempts, oldest first */
    private array $exhaustedRows = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $taskName,
    ) {}

    /**
     * Handles a failed row: when the failure closed the EntityManager, logs it, counts it against
     * the row and rethrows it, so the task stops the batch; once the row used up its attempts,
     * $skipRow moves the caller's cursor past it first. With an open (or no) EntityManager the
     * failure is row-specific and the caller logs it.
     *
     * @param string          $failedTo what failed, e.g. "failed to drop nick X (id 1)"
     * @param Closure(): void $skipRow  moves the caller's cursor past the row
     *
     * @throws Throwable $failure when the EntityManager is closed
     */
    public function rethrowWhenClosed(
        int $rowId,
        ?EntityManagerInterface $entityManager,
        Throwable $failure,
        string $failedTo,
        Closure $skipRow,
    ): void {
        if (null === $entityManager || $entityManager->isOpen()) {
            return;
        }

        $this->logger->error(
            sprintf('Maintenance [%s]: %s%s', $this->taskName, $failedTo, $this->judge($rowId, $entityManager, $skipRow)),
            ['exception' => $failure],
        );

        throw $failure;
    }

    /** Called after a row reached its own outcome: it no longer counts as failing. */
    public function rowSettled(int $rowId): void
    {
        $this->rowId = 0;
        $this->attempts = 0;
        unset($this->exhaustedRows[$rowId]);
    }

    /**
     * Counts the failure when the database answers and returns how the log line ends.
     *
     * @param Closure(): void $skipRow
     */
    private function judge(int $rowId, EntityManagerInterface $entityManager, Closure $skipRow): string
    {
        if (!$this->databaseAnswers($entityManager)) {
            return '; the EntityManager is closed, stopping this batch.';
        }

        if (isset($this->exhaustedRows[$rowId])) {
            $skipRow();

            return ' again after it was skipped in an earlier sweep; skipping it until the next sweep and stopping this batch.';
        }

        if ($this->rowId !== $rowId) {
            $this->rowId = $rowId;
            $this->attempts = 0;
        }

        if (++$this->attempts < self::MAX_ATTEMPTS) {
            return '; the EntityManager is closed, stopping this batch.';
        }

        $this->rowId = 0;
        $this->attempts = 0;
        $this->remember($rowId);
        $skipRow();

        return sprintf(
            ' with the EntityManager closed %d times in a row; skipping it until the next sweep and stopping this batch.',
            self::MAX_ATTEMPTS,
        );
    }

    private function remember(int $rowId): void
    {
        $this->exhaustedRows[$rowId] = true;
        if (count($this->exhaustedRows) > self::MAX_REMEMBERED_ROWS) {
            unset($this->exhaustedRows[array_key_first($this->exhaustedRows)]);
        }
    }

    /** The DBAL connection keeps working while the EntityManager is closed. */
    private function databaseAnswers(EntityManagerInterface $entityManager): bool
    {
        try {
            $connection = $entityManager->getConnection();
            $connection->fetchOne($connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
