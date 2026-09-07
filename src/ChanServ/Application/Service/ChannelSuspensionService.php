<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChannelSuspensionNotifier;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function sprintf;

/**
 * Handles IRC-level enforcement when a channel is suspended or unsuspended.
 *
 * Suspension: removes +rP modes and sends a notice to the channel.
 * Unsuspension: restores +rP modes (MLOCK and rank enforcement are handled
 * by existing subscribers on the next mode sync).
 */
readonly class ChannelSuspensionService
{
    public function __construct(
        private ChanNetworkActions $channelActions,
        private ChannelSuspensionNotifier $suspensionNotifier,
        private ChanServActivitySink $logger,
    ) {}

    public function enforceSuspension(RegisteredChannel $channel): void
    {
        $channelName = $channel->getName();

        $this->channelActions->removeRegistrationModes($channelName);
        $this->sendSuspensionNotice($channel);
    }

    public function liftSuspension(RegisteredChannel $channel): void
    {
        $channelName = $channel->getName();

        $this->channelActions->restoreRegistrationModes($channelName);
    }

    private function sendSuspensionNotice(RegisteredChannel $channel): void
    {
        $this->suspensionNotifier->notifyChannelSuspended(
            $channel->getName(),
            $channel->getSuspendedReason(),
        );

        $this->logger->info(sprintf(
            'ChannelSuspension: sent suspension notice to %s',
            $channel->getName(),
        ));
    }
}
