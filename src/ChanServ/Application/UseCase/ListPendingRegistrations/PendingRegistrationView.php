<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ListPendingRegistrations;

use DateTimeImmutable;

final readonly class PendingRegistrationView
{
    public function __construct(
        public string $channelName,
        public int $requesterAccountId,
        public ?string $requesterNickname,
        public string $description,
        public DateTimeImmutable $submittedAt,
    ) {}
}
