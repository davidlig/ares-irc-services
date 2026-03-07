<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

/**
 * Raw protocol event: NICK change received. Carries source and new nick string.
 * Enricher resolves source, updates repos, and dispatches UserNickChangedEvent.
 */
final readonly class NickChangeReceivedEvent
{
    public function __construct(
        /** Source identifier from message prefix (UID or nick string). */
        public readonly string $sourceId,
        public readonly string $newNickStr,
    ) {
    }
}
