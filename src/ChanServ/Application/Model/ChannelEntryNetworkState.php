<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

final readonly class ChannelEntryNetworkState
{
    /** @param list<ChannelEntryMember> $members */
    public function __construct(
        public string $name,
        public array $members,
    ) {}
}
