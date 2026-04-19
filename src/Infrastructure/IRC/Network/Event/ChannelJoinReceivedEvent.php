<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;

/**
 * @param array<int, array{uid: Uid, role: ChannelMemberRole, prefixLetters?: list<string>}> $members
 * @param array{b?: string[], e?: string[], I?: string[]}                                    $listModes
 * @param list<string>                                                                       $modeParams
 */
final readonly class ChannelJoinReceivedEvent
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
