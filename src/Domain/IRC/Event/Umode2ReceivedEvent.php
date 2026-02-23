<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

/**
 * Raw protocol event: UMODE2 received (UnrealIRCd). Carries source and mode string.
 * Enricher resolves source and dispatches UserModeChangedEvent.
 */
readonly class Umode2ReceivedEvent
{
    public function __construct(
        /** Source identifier from message prefix (UID or nick string). */
        public readonly string $sourceId,
        public readonly string $modeStr,
    ) {
    }
}
