<?php

declare(strict_types=1);

namespace App\Irc\Domain\Event;

use App\Irc\Domain\ValueObject\Uid;

/**
 * Dispatched when a user's displayed host changes (e.g. SETHOST from IRCd after CHGHOST).
 * Subscriber updates NetworkUser.virtualHost so displayHost stays in sync (avoids skipping
 * CHGHOST on identify when we think they already have the vhost).
 */
final readonly class UserHostChangedEvent
{
    public function __construct(
        public Uid $uid,
        public string $newHost,
    ) {}
}
