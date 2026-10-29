<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

final readonly class ChannelBanMatchResult
{
    /** @param list<string> $masks Original stored masks, not reconstructed identities. */
    public function __construct(
        public bool $channelExists,
        public array $masks = [],
        public ?int $channelTimestamp = null,
    ) {}
}
