<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\NotifyChannelRegistration;

use DateTimeImmutable;

final readonly class NotifyChannelRegistrationDecision
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public int $requesterAccountId,
        public int $operatorAccountId,
        public string $operatorNickname,
        public bool $accepted,
        public ?string $rejectionReason,
        public ?DateTimeImmutable $restrictedUntil,
        public DateTimeImmutable $occurredAt,
    ) {}
}
