<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\ChanServ\Domain\ValueObject\RankChange;

final readonly class MemberRankChange
{
    public function __construct(
        public string $uid,
        public RankChange $change,
    ) {}
}
