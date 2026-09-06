<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network\Event;

use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;

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
        public ChannelName $channelName,
        public int $timestamp,
        public string $modeStr,
        public array $members,
        public array $listModes = [],
        public array $modeParams = [],
    ) {}
}
