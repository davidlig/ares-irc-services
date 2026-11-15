<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * Keeps one channel that always fails from blocking the rest of a ChanServ maintenance sweep.
 *
 * A sweep visits every matching channel and may span several cycles: it ends when a cycle visits
 * the last channel. A row-specific failure (the EntityManager stays open) settles the channel for
 * the rest of the sweep, so it is skipped until the next sweep. A failure that closes the
 * EntityManager stops the cycle and the channel is retried next cycle; once it has closed the
 * EntityManager {@see self::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS} times in a row while the database
 * still answers, it is settled too. A database outage is never counted against a channel.
 *
 * Without an EntityManager every failure counts as row-specific; without a connection the
 * database is assumed to answer.
 *
 * @internal
 */
final class ChannelSweepFailureBudget
{
    public const int MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS = 3;

    /** @var array<int, true> Channels with an outcome in the current sweep that are still due. */
    private array $settled = [];

    /** Id of the channel whose last attempt closed the EntityManager; 0 when the last channel had its own outcome. */
    private int $closedFailureId = 0;

    private int $closedFailureAttempts = 0;

    public function __construct(
        private readonly ?EntityManagerInterface $entityManager = null,
        private readonly ?Connection $connection = null,
    ) {}

    public function isSettled(int $channelId): bool
    {
        return isset($this->settled[$channelId]);
    }

    public function entityManagerClosed(): bool
    {
        return null !== $this->entityManager && !$this->entityManager->isOpen();
    }

    /**
     * The channel had its own outcome; a failed channel is skipped until the next sweep.
     */
    public function completed(int $channelId, bool $failed): void
    {
        $this->closedFailureId = 0;
        if ($failed) {
            $this->settled[$channelId] = true;
        }
    }

    /**
     * Counts a failure of the channel that closed the EntityManager.
     *
     * @return bool true when the channel reached the limit in a row and is now skipped until the next sweep
     */
    public function closedEntityManagerFailure(int $channelId): bool
    {
        if (!$this->databaseAnswers()) {
            return false;
        }

        if ($this->closedFailureId !== $channelId) {
            $this->closedFailureId = $channelId;
            $this->closedFailureAttempts = 0;
        }

        if (++$this->closedFailureAttempts < self::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS) {
            return false;
        }

        $this->completed($channelId, true);

        return true;
    }

    public function finishSweep(): void
    {
        $this->settled = [];
    }

    private function databaseAnswers(): bool
    {
        if (null === $this->connection) {
            return true;
        }

        try {
            $this->connection->fetchOne($this->connection->getDatabasePlatform()->getDummySelectSQL());
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
