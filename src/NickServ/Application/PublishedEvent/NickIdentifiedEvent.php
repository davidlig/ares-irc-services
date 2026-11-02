<?php

declare(strict_types=1);

namespace App\NickServ\Application\PublishedEvent;

use App\NickServ\Application\Port\In\NickServEvent;

/**
 * Dispatched when a user successfully identifies with a registered nickname (IDENTIFY command).
 * Other services (e.g. MemoServ) may subscribe to notify about pending memos, etc.
 */
final readonly class NickIdentifiedEvent implements NickServEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        /** UID of the user who identified (for user-facing notifications). */
        public string $uid,
        /** Whether the identification was implicit (e.g. user connected with +r via IRCd). */
        public bool $implicit = false,
    ) {}
}
