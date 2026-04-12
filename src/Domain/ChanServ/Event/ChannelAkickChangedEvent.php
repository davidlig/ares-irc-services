<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Event;

use DateTimeImmutable;

final readonly class ChannelAkickChangedEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $action,
        public string $mask,
        public ?string $reason,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt = new DateTimeImmutable(),
    ) {
    }
}
