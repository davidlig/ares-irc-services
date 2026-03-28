<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

/**
 * Dispatched when a user loses their identified status (e.g., changes nick to an unregistered one).
 * This allows other services (like OperServ) to react and clean up state.
 */
final readonly class UserDeidentifiedEvent
{
    public function __construct(
        public string $uid,
        public int $nickId,
        public string $nickname,
    ) {
    }
}
