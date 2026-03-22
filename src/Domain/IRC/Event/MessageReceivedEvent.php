<?php

declare(strict_types=1);

namespace App\Domain\IRC\Event;

use App\Domain\IRC\Message\IRCMessage;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\Event;

final class MessageReceivedEvent extends Event
{
    private bool $propagationStopped = false;

    public function __construct(
        public readonly IRCMessage $message,
        public readonly DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }
}
