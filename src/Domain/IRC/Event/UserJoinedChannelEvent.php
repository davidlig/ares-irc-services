<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;

/**
 * Dispatched when a user joins a channel (post-burst SJOIN with a single user entry).
 */
readonly class UserJoinedChannelEvent
{
    public function __construct(
        public readonly Uid $uid,
        public readonly ChannelName $channel,
        public readonly ChannelMemberRole $role,
    ) {
    }
}
