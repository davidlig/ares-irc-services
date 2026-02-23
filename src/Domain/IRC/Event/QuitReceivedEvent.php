<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

/**
 * Raw protocol event: QUIT received. Carries only source (UID or nick) and reason.
 * Enricher resolves source, updates repos, and dispatches UserQuitNetworkEvent.
 */
readonly class QuitReceivedEvent
{
    public function __construct(
        /** Source identifier from message prefix (UID or nick string). */
        public readonly string $sourceId,
        public readonly string $reason,
    ) {
    }
}
