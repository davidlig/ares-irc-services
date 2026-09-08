<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelTopicNetworkState;

interface ChannelTopicNetworkQuery
{
    public function findChannel(string $channelName): ?ChannelTopicNetworkState;

    public function isSynchronizationCompleted(string $channelName): bool;

    public function synchronizationCompletedAt(string $channelName): ?float;

    public function resolveNickname(string $uid): ?string;
}
