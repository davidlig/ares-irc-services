<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;

/**
 * Dispatched when a user joins a channel (post-burst SJOIN with a single user entry).
 */
final readonly class UserJoinedChannelEvent
{
    public function __construct(
        public Uid $uid,
        public ChannelName $channel,
        public ChannelMemberRole $role,
    ) {}
}
