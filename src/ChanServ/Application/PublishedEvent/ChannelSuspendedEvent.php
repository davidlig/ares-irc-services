<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;
use DateTimeImmutable;

/**
 * Dispatched when a registered channel is suspended.
 */
final readonly class ChannelSuspendedEvent implements ChanServEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $channelNameLower,
        public string $reason,
        public ?string $duration,
        public ?DateTimeImmutable $expiresAt,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt,
    ) {}
}
