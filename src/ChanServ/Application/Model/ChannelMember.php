<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\ChanServ\Domain\ValueObject\ChannelRank;

final readonly class ChannelMember
{
    /** @param list<ChannelRank> $currentRanks */
    public function __construct(
        public string $uid,
        public string $nickname,
        public bool $identified,
        public bool $operator,
        public bool $service,
        public ?int $registeredNickId,
        public array $currentRanks,
    ) {}
}
