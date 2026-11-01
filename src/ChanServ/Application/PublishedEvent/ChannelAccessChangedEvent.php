<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;
use DateTimeImmutable;

final readonly class ChannelAccessChangedEvent implements ChanServEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $action,
        public int $targetNickId,
        public string $targetNickname,
        public ?int $level,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt,
    ) {}
}
