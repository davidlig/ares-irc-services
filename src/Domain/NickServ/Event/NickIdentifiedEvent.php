<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

/**
 * Dispatched when a user successfully identifies with a registered nickname (IDENTIFY command).
 * Other services (e.g. MemoServ) may subscribe to notify about pending memos, etc.
 */
final readonly class NickIdentifiedEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        /** UID of the user who identified (for sending NOTICEs). */
        public string $uid,
    ) {
    }
}
