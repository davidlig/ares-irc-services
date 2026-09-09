<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Model\ChannelTopicNetworkState;
use App\ChanServ\Application\Port\Out\ChannelTopicNetworkQuery;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelSyncCompletedRegistryInterface;
use App\Irc\Application\Port\In\UidResolverInterface;

final readonly class IrcChannelTopicNetworkQuery implements ChannelTopicNetworkQuery
{
    public function __construct(
        private ChannelLookupPort $channels,
        private ChannelSyncCompletedRegistryInterface $synchronization,
        private UidResolverInterface $users,
    ) {}

    public function findChannel(string $channelName): ?ChannelTopicNetworkState
    {
        $channel = $this->channels->findByChannelName($channelName);

        return null === $channel ? null : new ChannelTopicNetworkState($channel->name, $channel->topic);
    }

    public function isSynchronizationCompleted(string $channelName): bool
    {
        return $this->synchronization->isSyncCompleted($channelName);
    }

    public function synchronizationCompletedAt(string $channelName): ?float
    {
        return $this->synchronization->getSyncCompletedAt($channelName);
    }

    public function resolveNickname(string $uid): ?string
    {
        return $this->users->resolveUidToNick($uid);
    }
}
