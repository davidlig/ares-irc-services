<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;

/**
 * Dispatched when a NICK command is received: a user has changed their nickname.
 */
readonly class UserNickChangedEvent
{
    public function __construct(
        public readonly Uid $uid,
        public readonly Nick $oldNick,
        public readonly Nick $newNick,
    ) {
    }
}
