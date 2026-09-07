<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelMlockPolicy;

interface ChannelMlockPolicyRepository
{
    public function findByName(string $channelName): ?ChannelMlockPolicy;

    /** @return list<ChannelMlockPolicy> */
    public function all(): array;
}
