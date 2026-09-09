<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Event;

use App\Irc\Adapter\Protocol\IRCMessage;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

/** Dispatched after an IRC line is parsed and before protocol handling begins. */
final class IncomingIrcMessageEvent extends Event
{
    public function __construct(
        public readonly IRCMessage $message,
        public readonly DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {}
}
