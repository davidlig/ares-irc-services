<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;

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
