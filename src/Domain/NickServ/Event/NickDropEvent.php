<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a registered nickname is dropped (e.g. due to inactivity or manual DROP).
 * Other services (ChanServ, MemoServ) may subscribe to clean up channels, memos, etc.
 * Dispatched before the nick is removed from persistence; subscribers must use event payload only.
 */
readonly class NickDropEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public string $nicknameLower,
        /** Reason for drop: e.g. 'inactivity', 'manual' */
        public string $reason,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
