<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\Irc\Application\Port\In\Maintenance\MaintenanceTaskInterface;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function sprintf;

/**
 * Automatically unsuspends channels whose suspension has expired.
 * Dispatched periodically by the maintenance scheduler.
 *
 * A channel that fails to unsuspend is logged and does not stop the later channels; a failure
 * that closes the EntityManager stops the cycle and is bounded by {@see ChannelSweepFailureBudget}.
 *
 * Order 196: After NickServ unsuspend expired (195), before channel purge (200).
 */
final readonly class UnsuspendExpiredChannelsTask implements MaintenanceTaskInterface
{
    private ChannelSweepFailureBudget $failures;

    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private EventBusInterface $eventDispatcher,
        private LoggerInterface $logger,
        private string $serverName,
        private int $intervalSeconds,
        ?EntityManagerInterface $entityManager = null,
        ?Connection $connection = null,
    ) {
        $this->failures = new ChannelSweepFailureBudget($entityManager, $connection);
    }

    public function getName(): string
    {
        return 'chanserv.unsuspend_expired_channels';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 196;
    }

    /**
     * @throws Throwable when an unsuspension closed the EntityManager (the cycle stops; later channels run next cycle)
     */
    public function run(): void
    {
        $occurredAt = new DateTimeImmutable();
        $expired = $this->channelRepository->iterateExpiredSuspensions($occurredAt);

        foreach ($expired as $channel) {
            $channelId = $channel->getId();
            if ($this->failures->isSettled($channelId)) {
                continue;
            }
            $channelName = $channel->getName();
            $channelNameLower = $channel->getNameLower();

            try {
                $channel->unsuspend();
                $this->channelRepository->save($channel);

                $this->eventDispatcher->dispatch(new ChannelUnsuspendedEvent(
                    channelId: $channelId,
                    channelName: $channelName,
                    channelNameLower: $channelNameLower,
                    performedBy: $this->serverName,
                    performedByNickId: null,
                    performedByIp: '*',
                    performedByHost: '*',
                    occurredAt: $occurredAt,
                ));
            } catch (Throwable $e) {
                $this->recordFailure($channelId, $channelName, $e);

                continue;
            }

            $this->failures->completed($channelId, false);

            $this->logger->info(sprintf(
                'Maintenance [%s]: auto-unsuspended channel %s (id %d).',
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
                    'Maintenance [%s]: failed to auto-unsuspend channel %s (id %d) with the EntityManager closed %d times in a row; skipping it until the next sweep and stopping this batch.',
                    $this->getName(),
                    $channelName,
                    $channelId,
                    ChannelSweepFailureBudget::MAX_CLOSED_ENTITY_MANAGER_ATTEMPTS,
                ), ['exception' => $e]);

                throw $e;
            }

            $this->logger->error(sprintf(
                'Maintenance [%s]: failed to auto-unsuspend channel %s (id %d); the EntityManager is closed, stopping this batch.',
                $this->getName(),
                $channelName,
                $channelId,
            ), ['exception' => $e]);

            throw $e;
        }

        // A channel whose unsuspension was saved no longer matches, so skipping it loses nothing.
        $this->failures->completed($channelId, true);
        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to auto-unsuspend channel %s (id %d); skipping it until the next sweep.',
            $this->getName(),
            $channelName,
            $channelId,
        ), ['exception' => $e]);
    }
}
