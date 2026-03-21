<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Event;

use DateTimeImmutable;

/**
 * Dispatched when a channel is successfully registered.
 * Subscribers can react: apply +P (permanent mode), etc.
 */
final readonly class ChannelRegisteredEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $channelNameLower,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
