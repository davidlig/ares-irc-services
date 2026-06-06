<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Service;

use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\EventBusInterface;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Psr\Log\LoggerInterface;

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
        private EventBusInterface $eventDispatcher,
        private ServiceDebugNotifierInterface $debug,
        private LoggerInterface $logger,
        private ChannelServiceActionsPort $channelActions,
    ) {
    }

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

        $this->channelActions->setChannelModes($channelName, '-r');
        if ($channel->isNoExpire()) {
            $this->channelActions->setChannelModes($channelName, '-P');
        }

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

        $this->channelActions->setChannelModes($channelName, '+r');
        if ($channel->isNoExpire()) {
            $this->channelActions->setChannelModes($channelName, '+P');
        }

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

        $this->eventDispatcher->dispatch(new ChannelDropEvent(
            $channelId,
            $channelName,
            $channelNameLower,
            $reason,
        ));

        $this->channelRepository->delete($channel);

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
