<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

use DateTimeImmutable;

final readonly class SaregisterChannel
{
    public function __construct(
        public string $channelName,
        public string $founderNickname,
        public string $description,
        public string $actorNickname,
        public DateTimeImmutable $occurredAt,
    ) {}
}
