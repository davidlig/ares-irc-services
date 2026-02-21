<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;

/**
 * Dispatched when a QUIT command is received: a user has disconnected from the network.
 */
readonly class UserQuitNetworkEvent
{
    public function __construct(
        public readonly Uid $uid,
        public readonly Nick $nick,
        public readonly string $reason,
    ) {
    }
}
