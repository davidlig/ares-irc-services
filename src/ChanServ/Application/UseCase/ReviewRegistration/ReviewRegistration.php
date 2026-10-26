<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ReviewRegistration;

use DateTimeImmutable;

final readonly class ReviewRegistration
{
    public function __construct(
        public string $channelName,
        public bool $channelExistsOnNetwork,
        public int $operatorAccountId,
        public string $operatorNickname,
        public DateTimeImmutable $occurredAt,
        public ?string $rejectionReason = null,
        public bool $requesterHasRequiredChannelRank = false,
    ) {}
}
