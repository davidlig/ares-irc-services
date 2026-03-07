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
        public readonly Uid $uid,
        public readonly Nick $nick,
        public readonly ChannelName $channel,
        public readonly string $reason,
        public readonly bool $wasKicked,
    ) {
    }
}
