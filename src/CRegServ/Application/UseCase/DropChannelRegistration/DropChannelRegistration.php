<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DropChannelRegistration;

use DateTimeImmutable;

final readonly class DropChannelRegistration
{
    public function __construct(
        public string $channelName,
        public string $actorNickname,
        public DateTimeImmutable $occurredAt,
    ) {}
}
