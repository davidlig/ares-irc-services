<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Message\IRCMessage;
use DateTimeImmutable;

final readonly class MessageReceivedEvent
{
    public function __construct(
        public readonly IRCMessage $message,
        public readonly DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
