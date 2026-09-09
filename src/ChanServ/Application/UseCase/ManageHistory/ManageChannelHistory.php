<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageHistory;

use DateTimeImmutable;

final readonly class ManageChannelHistory
{
    public function __construct(
        public string $channelName,
        public ChannelHistoryAction $action,
        public string $actorNickname,
        public ?int $actorAccountId,
        public string $actorIp,
        public string $actorHost,
        public DateTimeImmutable $occurredAt,
        public ?string $message = null,
        public ?int $entryId = null,
        public int $page = 1,
        public bool $showAll = false,
    ) {}
}
