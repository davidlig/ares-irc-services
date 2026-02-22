<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\ValueObject\Uid;

/**
 * Dispatched when a user's modes change (e.g. UMODE2 from S2S, or protocol-specific
 * behaviour such as stripping +r on NICK). Subscriber applies the delta to the user.
 */
readonly class UserModeChangedEvent
{
    public function __construct(
        public readonly Uid $uid,
        /** Mode delta string, e.g. "+r" or "-r" */
        public readonly string $modeDelta,
    ) {
    }
}
