<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

/**
 * Raw protocol event: SETHOST received (UnrealIRCd). Carries source UID and new displayed host.
 * When a user's vhost is set or cleared, the server sends :uid SETHOST :newhost.
 * Enricher resolves UID and dispatches UserHostChangedEvent; subscriber updates NetworkUser.virtualHost.
 */
final readonly class SethostReceivedEvent
{
    public function __construct(
        /** Source UID from message prefix. */
        public readonly string $sourceId,
        /** New displayed host (vhost or cloaked). */
        public readonly string $newHost,
    ) {
    }
}
