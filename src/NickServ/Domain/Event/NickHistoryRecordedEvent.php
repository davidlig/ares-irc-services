<?php

declare(strict_types=1);

namespace App\NickServ\Domain\Event;

use App\NickServ\Domain\Entity\NickHistory;

/**
 * Dispatched when a history entry is recorded for a nickname.
 */
final readonly class NickHistoryRecordedEvent
{
    public function __construct(
        public NickHistory $history,
    ) {}
}
