<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_key_first;
use function count;
use function sprintf;

/**
 * Keeps one channel that always fails from blocking the rest of a ChanServ maintenance sweep.
 *
 * A sweep visits every matching channel and may span several cycles: it ends when a cycle visits
 * the last channel. A row-specific failure (the EntityManager stays open) settles the channel for
 * the rest of the sweep, so it is skipped until the next sweep. A failure that closes the
 * EntityManager stops the cycle and the channel is retried next cycle; once it has closed the
 * EntityManager {@see self::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS} times while the database still
 * answers, it is settled too. Attempts are counted per channel, so the outcome of another channel
 * never resets them; only the channel's own outcome does.
 *
 * A channel that used up its attempts is remembered across sweeps: in a later sweep it gets a
 * single attempt and, if it closes the EntityManager again, it is settled at once instead of
 * blocking the task for another full budget. A database outage is never counted against a channel.
 *
 * Without an EntityManager every failure counts as row-specific; without a connection the
 * database is assumed to answer.
 *
 * @internal
 */
final class ChannelSweepFailureBudget
{
    public const int MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS = 3;

    /** Channels remembered as exhausted, or with counted attempts; the oldest is forgotten first. */
    public const int MAX_REMEMBERED_CHANNELS = 1000;

    /** @var array<int, true> Channels with an outcome in the current sweep that are still due. */
    private array $settled = [];

    /** @var array<int, int> Counted closed-EntityManager failures per channel id, oldest first. */
    private array $attempts = [];

    /** @var array<int, true> Channels that used up their attempts in an earlier sweep, oldest first. */
    private array $exhausted = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $taskName,
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
        unset($this->attempts[$channelId]);
        if ($failed) {
            $this->settled[$channelId] = true;

            return;
        }

        unset($this->exhausted[$channelId]);
    }

    /**
     * Handles a failed channel and logs it with the task's wording:
     * - the EntityManager is closed: counts the failure (see the class docblock) and rethrows it,
     *   so the task stops the cycle;
     * - $committed tells the change was committed despite the failure: the channel is done and is
     *   reported as "<$committedAction> #chan (id N), but a <$postStep> step failed";
     * - otherwise the channel is skipped until the next sweep.
     *
     * @param string               $failedAction    logged as "failed to <$failedAction> #chan (id N)"
     * @param Closure(): bool|null $committed       asked only while the EntityManager is open
     * @param string               $committedAction e.g. "dropped inactive channel"
     * @param string               $postStep        e.g. "post-drop"
     *
     * @throws Throwable $failure when it closed the EntityManager
     */
    public function recordFailure(
        int $channelId,
        string $channelName,
        Throwable $failure,
        string $failedAction,
        ?Closure $committed = null,
        string $committedAction = '',
        string $postStep = '',
    ): void {
        if ($this->entityManagerClosed()) {
            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to %s %s (id %d)%s',
                $this->taskName,
                $failedAction,
                $channelName,
                $channelId,
                $this->closedEntityManagerFailure($channelId),
            ), ['exception' => $failure]);

            throw $failure;
        }

        if (null !== $committed && $committed()) {
            $this->completed($channelId, false);
            $this->logger->error(sprintf(
                'Maintenance [%s]: %s %s (id %d), but a %s step failed.',
                $this->taskName,
                $committedAction,
                $channelName,
                $channelId,
                $postStep,
            ), ['exception' => $failure]);

            return;
        }

        $this->completed($channelId, true);
        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to %s %s (id %d); skipping it until the next sweep.',
            $this->taskName,
            $failedAction,
            $channelName,
            $channelId,
        ), ['exception' => $failure]);
    }

    public function finishSweep(): void
    {
        $this->settled = [];
    }

    /**
     * Counts a failure of the channel that closed the EntityManager.
     *
     * @return string how the log line ends
     */
    private function closedEntityManagerFailure(int $channelId): string
    {
        if (!$this->databaseAnswers()) {
            return '; the EntityManager is closed, stopping this batch.';
        }

        if (isset($this->exhausted[$channelId])) {
            $this->settled[$channelId] = true;

            return ' again after it was skipped in an earlier sweep; skipping it until the next sweep and stopping this batch.';
        }

        $attempts = ($this->attempts[$channelId] ?? 0) + 1;
        unset($this->attempts[$channelId]);
        if ($attempts < self::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS) {
            $this->attempts[$channelId] = $attempts;
            self::bound($this->attempts);

            return '; the EntityManager is closed, stopping this batch.';
        }

        $this->settled[$channelId] = true;
        $this->exhausted[$channelId] = true;
        self::bound($this->exhausted);

        return sprintf(
            ' with the EntityManager closed %d times in a row; skipping it until the next sweep and stopping this batch.',
            self::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS,
        );
    }

    /**
     * @template T
     *
     * @param array<int, T> $channels
     */
    private static function bound(array &$channels): void
    {
        if (count($channels) > self::MAX_REMEMBERED_CHANNELS) {
            unset($channels[array_key_first($channels)]);
        }
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
