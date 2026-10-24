<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network\Event;

use App\Irc\Domain\ValueObject\Ident;

/** A partial identity update: null fields retain the current value. */
final readonly class UserIdentityReceivedEvent
{
    public function __construct(
        public string $sourceId,
        public ?string $displayHost = null,
        public ?string $realHost = null,
        public ?Ident $displayIdent = null,
        public ?Ident $realIdent = null,
    ) {}
}
