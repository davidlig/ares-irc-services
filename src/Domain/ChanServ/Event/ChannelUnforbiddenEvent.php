<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Event;

use DateTimeImmutable;

final readonly class ChannelUnforbiddenEvent
{
    public function __construct(
        public string $channelName,
        public string $channelNameLower,
        public string $performedBy,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
