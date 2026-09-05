<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;

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
