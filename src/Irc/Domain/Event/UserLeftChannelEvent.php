<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;

/**
 * Dispatched when a user leaves a channel via PART or is removed via KICK.
 */
final readonly class UserLeftChannelEvent
{
    public function __construct(
        public Uid $uid,
        public Nick $nick,
        public ChannelName $channel,
        public string $reason,
        public bool $wasKicked,
    ) {}
}
