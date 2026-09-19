<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceAkick;

use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

final readonly class SynchronizeAllChannelAkicksHandler implements SynchronizeAllChannelAkicksHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelEntryNetworkQuery $network,
        private EnforceChannelAkickHandlerInterface $enforceChannelAkick,
    ) {}

    public function handle(SynchronizeAllChannelAkicks $command): int
    {
        $enforced = 0;
        foreach ($this->channels->iterateAll() as $channel) {
            if ($channel->isBlocked()) {
                continue;
            }

            $networkChannel = $this->network->findChannel($channel->getName());
            if (null === $networkChannel) {
                continue;
            }

            $enforced += $this->enforceChannelAkick->handleKnownChannel(
                $channel,
                $networkChannel->members,
                $command->now,
            );
        }

        return $enforced;
    }
}
