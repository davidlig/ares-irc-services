<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceNojoin;

use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

final readonly class SynchronizeAllChannelNojoinHandler implements SynchronizeAllChannelNojoinHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelEntryNetworkQuery $network,
        private EnforceChannelNojoinHandlerInterface $enforceChannelNojoin,
    ) {}

    public function handle(): int
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

            $enforced += $this->enforceChannelNojoin->handleKnownChannel($channel, $networkChannel->members);
        }

        return $enforced;
    }
}
