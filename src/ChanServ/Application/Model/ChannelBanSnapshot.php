<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

final readonly class ChannelBanSnapshot
{
    /** @param list<string> $masks */
    public function __construct(
        public bool $channelExists,
        public array $masks = [],
        public ?int $channelTimestamp = null,
    ) {}
}
