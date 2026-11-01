<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;
use DateTimeImmutable;

final readonly class ChannelSuccessorChangedEvent implements ChanServEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public ?int $oldSuccessorNickId,
        public ?int $newSuccessorNickId,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt,
    ) {}
}
