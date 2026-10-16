<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\CancelChannelRegistration;

use DateTimeImmutable;

final readonly class CancelChannelRegistration
{
    public function __construct(
        public string $channelName,
        public int $requesterAccountId,
        public DateTimeImmutable $occurredAt,
    ) {}
}
