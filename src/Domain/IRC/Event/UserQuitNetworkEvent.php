<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;

/**
 * Dispatched when a QUIT command is received: a user has disconnected from the network.
 *
 * NOTE: this event is dispatched BEFORE the user is removed from the
 * NetworkUserRepository so subscribers can still access the full user object
 * if needed. ident and displayHost are provided directly to avoid re-lookups.
 */
final readonly class UserQuitNetworkEvent
{
    public function __construct(
        public readonly Uid $uid,
        public readonly Nick $nick,
        public readonly string $reason,
        /** IRC ident (username) of the user, e.g. "david" */
        public readonly string $ident = '',
        /** Best available hostname (vhost > cloaked host), e.g. "Clk-1C178BB8" */
        public readonly string $displayHost = '',
    ) {
    }
}
