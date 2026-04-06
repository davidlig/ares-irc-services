<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Event;

use App\Domain\NickServ\Entity\NickHistory;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a history entry is recorded for a nickname.
 */
final class NickHistoryRecordedEvent extends Event
{
    public function __construct(
        public readonly NickHistory $history,
    ) {
    }
}
