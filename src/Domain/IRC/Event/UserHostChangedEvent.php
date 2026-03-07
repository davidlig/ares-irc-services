<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\Uid;

/**
 * Dispatched when a user's displayed host changes (e.g. SETHOST from IRCd after CHGHOST).
 * Subscriber updates NetworkUser.virtualHost so displayHost stays in sync (avoids skipping
 * CHGHOST on identify when we think they already have the vhost).
 */
final readonly class UserHostChangedEvent
{
    public function __construct(
        public readonly Uid $uid,
        public readonly string $newHost,
    ) {
    }
}
