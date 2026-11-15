<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Maintenance;

use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Bounds how often one maintenance row may close the EntityManager before it is skipped.
 *
 * Doctrine closes the EntityManager on any failure inside a flush, so a row that always fails
 * would be retried forever. A failure counts against the row only while the database still
 * answers: during an outage every row closes the EntityManager, so the failure says nothing
 * about the row and must neither count nor skip it.
 */
final class ClosedEntityManagerRetryBudget
{
    /** Consecutive closed-EntityManager failures after which a row is skipped until the next sweep. */
    public const int MAX_ATTEMPTS = 3;

    /** Id of the row whose last counted attempt closed the EntityManager; 0 when none. */
    private int $rowId = 0;

    private int $attempts = 0;

    /**
     * Records that processing the row closed the EntityManager and tells whether the row used up
     * its attempts; the count then restarts so the row gets a full budget on the next sweep.
     */
    public function exhaustedBy(int $rowId, EntityManagerInterface $entityManager): bool
    {
        if (!$this->databaseAnswers($entityManager)) {
            return false;
        }

        if ($this->rowId !== $rowId) {
            $this->rowId = $rowId;
            $this->attempts = 0;
        }

        if (++$this->attempts < self::MAX_ATTEMPTS) {
            return false;
        }

        $this->reset();

        return true;
    }

    /** Called after a row reached its own outcome, so only consecutive failures of one row count. */
    public function reset(): void
    {
        $this->rowId = 0;
        $this->attempts = 0;
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
