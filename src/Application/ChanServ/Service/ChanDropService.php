<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Service;

use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Centralized service for dropping registered channels.
 *
 * Handles all necessary cleanup when dropping a channel:
 * - Dispatches ChannelDropEvent for cleanup by other services
 * - Deletes from repository
 * - Logs to debug channel (if configured) and ircops.log
 */
readonly class ChanDropService
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private EventDispatcherInterface $eventDispatcher,
        private ServiceDebugNotifierInterface $debug,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Drops a registered channel with full cleanup.
     *
     * @param RegisteredChannel $channel      The channel to drop
     * @param string            $reason       Drop reason: 'manual' (IRCop) or 'inactivity' (maintenance)
     * @param string|null       $operatorNick Operator nickname for debug logging (null for maintenance)
     */
    public function dropChannel(
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
}
