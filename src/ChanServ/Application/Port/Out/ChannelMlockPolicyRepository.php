<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Domain\Entity\RegisteredChannel;

interface ChannelMlockPolicyRepository
{
    public function findByName(string $channelName): ?ChannelMlockPolicy;

    public function forChannel(RegisteredChannel $channel): ChannelMlockPolicy;

    /** @return iterable<ChannelMlockPolicy> */
    public function all(): iterable;
}
