<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearUsers;

use DateTimeImmutable;

final readonly class ClearChannelUsers
{
    public function __construct(
        public string $channelName,
        public string $reason,
        public string $actorNickname,
        public DateTimeImmutable $occurredAt,
    ) {}
}
