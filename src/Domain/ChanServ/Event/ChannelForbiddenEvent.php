<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Event;

use DateTimeImmutable;

final readonly class ChannelForbiddenEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $channelNameLower,
        public string $reason,
        public string $performedBy,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
