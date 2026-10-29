<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Model\ChannelBanSnapshot;
use App\ChanServ\Application\Port\Out\ChannelBanNetworkQuery;
use App\Irc\Application\Port\In\ChannelBanMatchQuery;

final readonly class IrcChannelBanNetworkQuery implements ChannelBanNetworkQuery
{
    public function __construct(private ChannelBanMatchQuery $query) {}

    public function findMatchingBans(string $channelName, string $uid): ?ChannelBanSnapshot
    {
        $result = $this->query->findMatchingBans($channelName, $uid);

        return null === $result ? null : new ChannelBanSnapshot($result->channelExists, $result->masks, $result->channelTimestamp);
    }
}
