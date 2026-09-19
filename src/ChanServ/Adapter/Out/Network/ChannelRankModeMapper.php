<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Domain\ValueObject\ChannelRank;

final readonly class ChannelRankModeMapper
{
    public function fromLetter(string $letter): ?ChannelRank
    {
        return match ($letter) {
            'q' => ChannelRank::Owner,
            'a' => ChannelRank::Administrator,
            'o' => ChannelRank::Operator,
            'h' => ChannelRank::HalfOperator,
            'v' => ChannelRank::Voice,
            default => null,
        };
    }

    public function toLetter(ChannelRank $rank): string
    {
        return match ($rank) {
            ChannelRank::Owner => 'q',
            ChannelRank::Administrator => 'a',
            ChannelRank::Operator => 'o',
            ChannelRank::HalfOperator => 'h',
            ChannelRank::Voice => 'v',
        };
    }
}
