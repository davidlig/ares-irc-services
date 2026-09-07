<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\ChanServ\Domain\ValueObject\ChannelRank;

final readonly class ChannelRankNetworkState
{
    /**
     * @param list<ChannelMember> $members
     * @param list<ChannelRank>   $supportedRanks
     */
    public function __construct(
        public string $name,
        public array $members,
        public array $supportedRanks,
    ) {}

    public function member(string $uid): ?ChannelMember
    {
        foreach ($this->members as $member) {
            if ($member->uid === $uid) {
                return $member;
            }
        }

        return null;
    }
}
