<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\EnforceRanks;

use App\ChanServ\Domain\ValueObject\ChannelRank;

final readonly class EnforceChannelRanks
{
    public function __construct(
        public string $channelName,
        public RankEnforcementTrigger $trigger,
        public ?string $uid = null,
        public ?ChannelRank $grantedRank = null,
        public ?ChannelRank $joinedRank = null,
    ) {}
}
