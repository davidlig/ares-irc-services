<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelRankPolicy;

interface ChannelRankPolicyRepository
{
    public function findByName(string $channelName): ?ChannelRankPolicy;

    /** @return iterable<ChannelRankPolicy> */
    public function all(): iterable;

    public function touchLastUsed(int $channelId): void;
}
