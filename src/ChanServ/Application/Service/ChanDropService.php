<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChanAuditSink;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function sprintf;

/**
 * Centralized service for dropping registered channels.
 *
 * Handles all necessary cleanup when dropping a channel:
 * - For soft drops, marks the channel pending deletion without cleanup events
 * - For hard drops, dispatches ChannelDropEvent and deletes from repository
 * - Logs to debug channel (if configured) and ircops.log
 */
readonly class ChanDropService
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanServEventPublisher $eventPublisher,
        private ChanAuditSink $debug,
        private ChanServActivitySink $logger,
        private ChanNetworkActions $channelActions,
        private ChanTransactionBoundary $transactionBoundary,
    ) {}

    /**
     * Starts a recoverable manual drop without cleaning dependent data.
     */
    public function softDropChannel(
        RegisteredChannel $channel,
        ?string $operatorNick = null,
    ): void {
        $channelName = $channel->getName();

        $channel->markPendingDeletion();
        $this->channelRepository->save($channel);

        $this->channelActions->removeRegistrationForPendingDeletion(
            $channelName,
            $channel->isNoExpire(),
            $channel->getCreatedAt()->getTimestamp(),
        );

        $this->debug->log(
            operator: $operatorNick ?? '*',
            command: 'DROP',
            target: $channelName,
            reason: 'manual',
            extra: ['soft_delete' => true],
        );

        $this->logger->info(sprintf(
            'ChanDrop: %s (id %d) marked pending deletion. Operator: %s.',
            $channelName,
            $channel->getId(),
            $operatorNick ?? 'maintenance',
        ));
    }

    public function restoreChannel(RegisteredChannel $channel, ?string $operatorNick = null): void
    {
        $channelName = $channel->getName();

        $channel->restoreFromPendingDeletion();
        $this->channelRepository->save($channel);

        $this->channelActions->restoreRegistrationAfterPendingDeletion(
            $channelName,
            $channel->isNoExpire(),
            $channel->getCreatedAt()->getTimestamp(),
        );

        $this->debug->log(
            operator: $operatorNick ?? '*',
            command: 'RESTORE',
            target: $channelName,
            reason: 'manual',
        );

        $this->logger->info(sprintf(
            'ChanRestore: %s (id %d) restored from pending deletion. Operator: %s.',
            $channelName,
            $channel->getId(),
            $operatorNick ?? 'maintenance',
        ));
    }

    /**
     * Permanently drops a registered channel with full cleanup.
     *
     * @param RegisteredChannel $channel      The channel to drop
     * @param string            $reason       Drop reason: 'manual' (IRCop) or 'inactivity' (maintenance)
     * @param string|null       $operatorNick Operator nickname for debug logging (null for maintenance)
     */
    public function hardDropChannel(
        RegisteredChannel $channel,
        string $reason = 'manual',
        ?string $operatorNick = null,
    ): void {
        $channelId = $channel->getId();
        $channelName = $channel->getName();
        $channelNameLower = $channel->getNameLower();

        $cleanupEvent = new ChannelDropCleanupEvent(
            $channelId,
            $channelName,
            $channelNameLower,
            $reason,
        );

        $this->transactionBoundary->transactional(function () use ($cleanupEvent, $channel): void {
            $this->eventPublisher->publish($cleanupEvent);
            $this->channelRepository->delete($channel);
        });

        $this->eventPublisher->publish(new ChannelDropEvent(
            $channelId,
            $channelName,
            $channelNameLower,
            $reason,
            $cleanupEvent->occurredAt,
        ));

        $this->debug->log(
            operator: $operatorNick ?? '*',
            command: 'DROP',
            target: $channelName,
            reason: $reason,
        );

        $this->logger->info(sprintf(
            'ChanDrop: %s (id %d) dropped. Reason: %s. Operator: %s.',
            $channelName,
            $channelId,
            $reason,
            $operatorNick ?? 'maintenance',
        ));
    }

    public function dropChannel(
        RegisteredChannel $channel,
        string $reason = 'manual',
        ?string $operatorNick = null,
    ): void {
        $this->hardDropChannel($channel, $reason, $operatorNick);
    }
}
