<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DenyChannelRegistration;

use DateTimeImmutable;

final readonly class DenyChannelRegistration
{
    public function __construct(
        public string $channelName,
        public string $reason,
        public string $denierNickname,
        public DateTimeImmutable $occurredAt,
    ) {}
}
