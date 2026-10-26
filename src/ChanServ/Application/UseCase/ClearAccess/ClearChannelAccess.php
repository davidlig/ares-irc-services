<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearAccess;

use DateTimeImmutable;

final readonly class ClearChannelAccess
{
    public function __construct(
        public string $channelName,
        public string $actorNickname,
        public DateTimeImmutable $occurredAt,
    ) {}
}
