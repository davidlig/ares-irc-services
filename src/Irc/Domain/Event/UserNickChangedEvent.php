<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;

/**
 * Dispatched when a NICK command is received: a user has changed their nickname.
 */
final readonly class UserNickChangedEvent
{
    public function __construct(
        public Uid $uid,
        public Nick $oldNick,
        public Nick $newNick,
    ) {}
}
