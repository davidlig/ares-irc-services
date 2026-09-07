<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelRankPolicy;

interface ChannelRankPolicyRepository
{
    public function findByName(string $channelName): ?ChannelRankPolicy;

    /** @return list<ChannelRankPolicy> */
    public function all(): array;

    public function touchLastUsed(int $channelId): void;
}
