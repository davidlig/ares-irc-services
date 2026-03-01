<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * DTO for "channel on network" as seen by Services (e.g. ChanServ).
 *
 * Services MUST NOT depend on Domain\IRC\Network\Channel.
 * Core implements ChannelLookupPort and returns this DTO.
 *
 * @phpstan-type MemberEntry array{uid: string, roleLetter: string}
 */
readonly class ChannelView
{
    /**
     * @param MemberEntry[]        $members    UID => role letter (v, h, o, a, q) for SECURE/ACCESS checks
     * @param int                  $timestamp  Unix timestamp of channel creation (for SJOIN when adding service to existing channel)
     * @param array<string,string> $modeParams Mode letter => param value (e.g. k => password, L => #channel) for MLOCK -k/-L
     */
    public function __construct(
        public string $name,
        public string $modes,
        public ?string $topic,
        public int $memberCount,
        public array $members = [],
        public int $timestamp = 0,
        public array $modeParams = [],
    ) {
    }

    public function getModeParam(string $letter): ?string
    {
        return $this->modeParams[$letter] ?? null;
    }
}
