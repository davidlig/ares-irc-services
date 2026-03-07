<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;

/**
 * Raw protocol event: FJOIN/SJOIN received. Carries channel, timestamp, modes, mode params, member list, and optional list modes.
 * Enricher findOrCreates channel, syncs members, saves, and dispatches ChannelSyncedEvent / UserJoinedChannelEvent.
 *
 * @param array<int, array{uid: Uid, role: ChannelMemberRole, prefixLetters?: list<string>}> $members
 * @param array{b?: string[], e?: string[], I?: string[]}                                    $listModes  Optional ban/exempt/invite from SJOIN buffer
 * @param list<string>                                                                       $modeParams Params for modes that take one (e.g. k, L) in wire order
 */
readonly class FjoinReceivedEvent
{
    /**
     * @param array<int, array{uid: Uid, role: ChannelMemberRole, prefixLetters?: list<string>}> $members
     * @param array{b?: string[], e?: string[], I?: string[]}                                    $listModes
     * @param list<string>                                                                       $modeParams
     */
    public function __construct(
        public readonly ChannelName $channelName,
        public readonly int $timestamp,
        public readonly string $modeStr,
        public readonly array $members,
        public readonly array $listModes = [],
        public readonly array $modeParams = [],
    ) {
    }
}
