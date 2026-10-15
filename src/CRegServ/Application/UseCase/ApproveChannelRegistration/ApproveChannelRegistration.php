<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ApproveChannelRegistration;

use DateTimeImmutable;

final readonly class ApproveChannelRegistration
{
    public function __construct(
        public string $channelName,
        public string $approverNickname,
        public DateTimeImmutable $occurredAt,
    ) {}
}
