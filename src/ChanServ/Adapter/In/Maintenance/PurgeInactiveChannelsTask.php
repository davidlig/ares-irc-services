<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\ChannelActivityPendingQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\Irc\Application\Port\In\Maintenance\PrerequisiteAwareMaintenanceTaskInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function sprintf;

/**
 * Removes registered channels that have been inactive for more than the configured days.
 * Delegates hard deletion so all dependent data is cleaned up atomically.
 *
 * The stored last-used timestamp lags behind deferred channel activity, so this task
 * requires the activity flush to have succeeded in the same cycle, and still keeps any
 * channel whose activity is not yet persisted (for example after a failed flush chunk).
 *
 * A channel whose hard drop fails is logged and does not stop the later channels; a failure
 * that closes the EntityManager stops the cycle and is bounded by {@see ChannelSweepFailureBudget}.
 *
 * Order 300: ChanServ channel expiry range, right after the activity flush (299).
 */
final readonly class PurgeInactiveChannelsTask implements PrerequisiteAwareMaintenanceTaskInterface
{
    private ChannelSweepFailureBudget $failures;

    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanDropService $dropService,
        private ChannelActivityPendingQuery $pendingActivity,
        private LoggerInterface $logger,
        private int $intervalSeconds,
        private int $inactivityExpiryDays,
        ?EntityManagerInterface $entityManager = null,
        ?Connection $connection = null,
    ) {
        $this->failures = new ChannelSweepFailureBudget($entityManager, $connection);
    }

    public function getName(): string
    {
        return 'chanserv.purge_inactive_channels';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 300;
    }

    public function getPrerequisiteTaskNames(): array
    {
        return [FlushChannelActivityTask::NAME];
    }

    /**
     * @throws Throwable when a hard drop closed the EntityManager (the cycle stops; later channels run next cycle)
     */
    public function run(): void
    {
        if ($this->inactivityExpiryDays <= 0) {
            return;
        }

        $now = new DateTimeImmutable();
        $threshold = $now->modify(sprintf('-%d days', $this->inactivityExpiryDays));
        $inactive = $this->channelRepository->iterateRegisteredInactiveSince($threshold);

        foreach ($inactive as $channel) {
            // Doctrine unsets the id once the drop is flushed; keep what the logs need.
            $channelId = $channel->getId();
            if ($this->failures->isSettled($channelId)) {
                continue;
            }

            $channelName = $channel->getName();
            $lastActivity = $channel->getLastUsedAt() ?? $channel->getCreatedAt();
            $lastActivityStr = $lastActivity->format('Y-m-d H:i:s');

            if ($this->pendingActivity->hasPendingActivity($channelId)) {
                $this->logger->info(
                    sprintf(
                        'Maintenance [%s]: kept channel %s (id %d) because it has unflushed activity (stored last activity: %s).',
                        $this->getName(),
                        $channelName,
                        $channelId,
                        $lastActivityStr,
                    ),
                );

                continue;
            }

            try {
                $this->dropService->hardDropChannel($channel, $now, 'inactivity');
            } catch (Throwable $e) {
                $this->recordFailure($channelId, $channelName, $e);

                continue;
            }

            $this->failures->completed($channelId, false);

            $this->logger->info(
                sprintf(
                    'Maintenance [%s]: deleted channel %s (id %d) due to inactivity (last activity: %s).',
                    $this->getName(),
                    $channelName,
                    $channelId,
                    $lastActivityStr,
                ),
            );
        }

        $this->failures->finishSweep();
    }

    /**
     * @throws Throwable when the EntityManager was closed by the failure
     */
    private function recordFailure(int $channelId, string $channelName, Throwable $e): void
    {
        if ($this->failures->entityManagerClosed()) {
            if ($this->failures->closedEntityManagerFailure($channelId)) {
                $this->logger->error(sprintf(
                    'Maintenance [%s]: failed to drop inactive channel %s (id %d) with the EntityManager closed %d times in a row; skipping it until the next sweep and stopping this batch.',
                    $this->getName(),
                    $channelName,
                    $channelId,
                    ChannelSweepFailureBudget::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS,
                ), ['exception' => $e]);

                throw $e;
            }

            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to drop inactive channel %s (id %d); the EntityManager is closed, stopping this batch.',
                $this->getName(),
                $channelName,
                $channelId,
            ), ['exception' => $e]);

            throw $e;
        }

        if ([] === $this->channelRepository->findByIds([$channelId])) {
            $this->failures->completed($channelId, false);
            $this->logger->error(sprintf(
                'Maintenance [%s]: dropped inactive channel %s (id %d), but a post-drop step failed.',
                $this->getName(),
                $channelName,
                $channelId,
            ), ['exception' => $e]);

            return;
        }

        $this->failures->completed($channelId, true);
        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to drop inactive channel %s (id %d); skipping it until the next sweep.',
            $this->getName(),
            $channelName,
            $channelId,
        ), ['exception' => $e]);
    }
}
