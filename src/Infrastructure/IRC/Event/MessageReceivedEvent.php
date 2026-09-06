<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Event;

use App\Irc\Adapter\Protocol\IRCMessage;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

final class MessageReceivedEvent extends Event
{
    public function __construct(
        public readonly IRCMessage $message,
        public readonly DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
