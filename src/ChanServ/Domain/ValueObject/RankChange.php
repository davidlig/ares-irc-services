<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

final readonly class RankChange
{
    public function __construct(
        public ChannelRank $rank,
        public RankChangeAction $action,
    ) {}
}
