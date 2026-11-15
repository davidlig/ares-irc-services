<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function max;
use function sprintf;

/**
 * Permanently deletes channels whose DROP grace period has expired.
 *
 * A channel whose hard drop fails is logged and does not stop the later channels; a failure
 * that closes the EntityManager stops the cycle and is bounded by {@see ChannelSweepFailureBudget}.
 *
 * Order 310: ChanServ channel expiry range, after the inactive-channel purge (300).
 */
final readonly class PurgePendingDeletionChannelsTask implements MaintenanceTaskInterface
{
    private ChannelSweepFailureBudget $failures;

    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanDropService $dropService,
        private LoggerInterface $logger,
        private int $intervalSeconds,
        private int $dropGraceDays,
        ?EntityManagerInterface $entityManager = null,
        ?Connection $connection = null,
    ) {
        $this->failures = new ChannelSweepFailureBudget($entityManager, $connection);
    }

    public function getName(): string
    {
        return 'chanserv.purge_pending_deletion_channels';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 310;
    }

    /**
     * @throws Throwable when a hard drop closed the EntityManager (the cycle stops; later channels run next cycle)
     */
    public function run(): void
    {
        $now = new DateTimeImmutable();
        $threshold = $now->modify(sprintf('-%d days', max(0, $this->dropGraceDays)));
        $expired = $this->channelRepository->iteratePendingDeletionBefore($threshold);

        foreach ($expired as $channel) {
            // Doctrine unsets the id once the drop is flushed; keep what the logs need.
            $channelId = $channel->getId();
            if ($this->failures->isSettled($channelId)) {
                continue;
            }
            $channelName = $channel->getName();

            try {
                $this->dropService->hardDropChannel($channel, $now, 'manual-grace-expired', null);
            } catch (Throwable $e) {
                $this->recordFailure($channelId, $channelName, $e);

                continue;
            }

            $this->failures->completed($channelId, false);
            $this->logger->info(sprintf(
                'Maintenance [%s]: permanently deleted channel %s (id %d) after DROP grace period.',
                $this->getName(),
                $channelName,
                $channelId,
            ));
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
                    'Maintenance [%s]: failed to delete pending-deletion channel %s (id %d) with the EntityManager closed %d times in a row; skipping it until the next sweep and stopping this batch.',
                    $this->getName(),
                    $channelName,
                    $channelId,
                    ChannelSweepFailureBudget::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS,
                ), ['exception' => $e]);

                throw $e;
            }

            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to delete pending-deletion channel %s (id %d); the EntityManager is closed, stopping this batch.',
                $this->getName(),
                $channelName,
                $channelId,
            ), ['exception' => $e]);

            throw $e;
        }

        if ([] === $this->channelRepository->findByIds([$channelId])) {
            $this->failures->completed($channelId, false);
            $this->logger->error(sprintf(
                'Maintenance [%s]: permanently deleted channel %s (id %d), but a post-drop step failed.',
                $this->getName(),
                $channelName,
                $channelId,
            ), ['exception' => $e]);

            return;
        }

        $this->failures->completed($channelId, true);
        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to delete pending-deletion channel %s (id %d); skipping it until the next sweep.',
            $this->getName(),
            $channelName,
            $channelId,
        ), ['exception' => $e]);
    }
}
