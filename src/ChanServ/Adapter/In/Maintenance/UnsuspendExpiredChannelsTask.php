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

use function array_key_first;
use function count;
use function sprintf;

/**
 * Automatically unsuspends channels whose suspension has expired.
 * Dispatched periodically by the maintenance scheduler.
 *
 * A channel that fails to unsuspend is logged and does not stop the later channels; a failure
 * that closes the EntityManager stops the cycle and is bounded by {@see ChannelSweepFailureBudget}.
 *
 * Once the unsuspension is saved the channel no longer matches the expiry query, so a failure
 * of a ChannelUnsuspendedEvent listener (UDB record removal, network restoration) would never be
 * retried by the sweep. Such a channel is kept in memory and its event is dispatched again in the
 * following cycles, after the sweep, at most {@see self::MAX_UNSUSPENSION_DISPATCH_RETRIES} times;
 * it is forgotten when the channel was dropped or suspended again meanwhile.
 *
 * Order 196: After NickServ unsuspend expired (195), before channel purge (200).
 */
final class UnsuspendExpiredChannelsTask implements MaintenanceTaskInterface
{
    public const int MAX_UNSUSPENSION_DISPATCH_RETRIES = 3;

    /** Channels whose unsuspension is dispatched again; the oldest is forgotten first. */
    public const int MAX_PENDING_UNSUSPENSION_DISPATCHES = 1000;

    private readonly ChannelSweepFailureBudget $failures;

    /** @var array<int, array{event: ChannelUnsuspendedEvent, retries: int}> */
    private array $pendingDispatches = [];

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly EventBusInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly string $serverName,
        private readonly int $intervalSeconds,
        ?EntityManagerInterface $entityManager = null,
        ?Connection $connection = null,
    ) {
        $this->failures = new ChannelSweepFailureBudget($logger, $this->getName(), $entityManager, $connection);
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
     * @throws Throwable when an unsuspension, or dispatching one again, closed the EntityManager (the cycle stops)
     */
    public function run(): void
    {
        // Only channels that failed in an earlier cycle are dispatched again in this one.
        $due = $this->pendingDispatches;
        $occurredAt = new DateTimeImmutable();
        $expired = $this->channelRepository->iterateExpiredSuspensions($occurredAt);

        foreach ($expired as $channel) {
            $channelId = $channel->getId();
            if ($this->failures->isSettled($channelId)) {
                continue;
            }
            $channelName = $channel->getName();

            try {
                $channel->unsuspend();
                $this->channelRepository->save($channel);
            } catch (Throwable $e) {
                $this->failures->recordFailure($channelId, $channelName, $e, 'auto-unsuspend channel');

                continue;
            }

            $this->failures->completed($channelId, false);
            unset($this->pendingDispatches[$channelId], $due[$channelId]);
            $event = new ChannelUnsuspendedEvent(
                channelId: $channelId,
                channelName: $channelName,
                channelNameLower: $channel->getNameLower(),
                performedBy: $this->serverName,
                performedByNickId: null,
                performedByIp: '*',
                performedByHost: '*',
                occurredAt: $occurredAt,
            );

            try {
                $this->eventDispatcher->dispatch($event);
            } catch (Throwable $e) {
                $this->postUnsuspendStepFailed($event, $e);

                continue;
            }

            $this->logger->info(sprintf(
                'Maintenance [%s]: auto-unsuspended channel %s (id %d).',
                $this->getName(),
                $channelName,
                $channelId,
            ));
        }

        $this->failures->finishSweep();

        foreach ($due as $channelId => $pending) {
            $this->dispatchAgain($channelId, $pending['event'], $pending['retries'] + 1);
        }
    }

    /**
     * @throws Throwable $failure when it closed the EntityManager
     */
    private function postUnsuspendStepFailed(ChannelUnsuspendedEvent $event, Throwable $failure): void
    {
        $this->pendingDispatches[$event->channelId] = ['event' => $event, 'retries' => 0];
        if (count($this->pendingDispatches) > self::MAX_PENDING_UNSUSPENSION_DISPATCHES) {
            unset($this->pendingDispatches[array_key_first($this->pendingDispatches)]);
        }

        $closed = $this->failures->entityManagerClosed();
        $this->logger->error(sprintf(
            'Maintenance [%s]: auto-unsuspended channel %s (id %d), but a post-unsuspend step failed; dispatching its unsuspension again next cycle%s.',
            $this->getName(),
            $event->channelName,
            $event->channelId,
            $closed ? ' and stopping this batch' : '',
        ), ['exception' => $failure]);

        if ($closed) {
            throw $failure;
        }
    }

    /**
     * @throws Throwable when the dispatch closed the EntityManager
     */
    private function dispatchAgain(int $channelId, ChannelUnsuspendedEvent $event, int $retry): void
    {
        $channel = $this->channelRepository->findByIds([$channelId])[0] ?? null;
        if (null === $channel || $channel->isSuspended()) {
            unset($this->pendingDispatches[$channelId]);
            $this->logger->info(sprintf(
                'Maintenance [%s]: no longer dispatching the unsuspension of channel %s (id %d) again; it was dropped or suspended again.',
                $this->getName(),
                $event->channelName,
                $channelId,
            ));

            return;
        }

        try {
            $this->eventDispatcher->dispatch($event);
        } catch (Throwable $e) {
            $this->dispatchAgainFailed($channelId, $event, $retry, $e);

            return;
        }

        unset($this->pendingDispatches[$channelId]);
        $this->logger->info(sprintf(
            'Maintenance [%s]: dispatched the unsuspension of channel %s (id %d) again.',
            $this->getName(),
            $event->channelName,
            $channelId,
        ));
    }

    /**
     * @throws Throwable $failure when it closed the EntityManager
     */
    private function dispatchAgainFailed(int $channelId, ChannelUnsuspendedEvent $event, int $retry, Throwable $failure): void
    {
        if ($retry >= self::MAX_UNSUSPENSION_DISPATCH_RETRIES) {
            unset($this->pendingDispatches[$channelId]);
            $outcome = sprintf('again %d times; giving up', self::MAX_UNSUSPENSION_DISPATCH_RETRIES);
        } else {
            $this->pendingDispatches[$channelId] = ['event' => $event, 'retries' => $retry];
            $outcome = 'again; trying again next cycle';
        }

        $closed = $this->failures->entityManagerClosed();
        $this->logger->error(sprintf(
            'Maintenance [%s]: failed to dispatch the unsuspension of channel %s (id %d) %s%s.',
            $this->getName(),
            $event->channelName,
            $channelId,
            $outcome,
            $closed ? ' and stopping this batch' : '',
        ), ['exception' => $failure]);

        if ($closed) {
            throw $failure;
        }
    }
}
