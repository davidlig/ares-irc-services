<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Message\IRCMessage;
use DateTimeImmutable;

final readonly class MessageReceivedEvent
{
    public function __construct(
        public IRCMessage $message,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
