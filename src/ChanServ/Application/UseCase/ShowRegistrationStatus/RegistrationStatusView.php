<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ShowRegistrationStatus;

use App\ChanServ\Domain\ValueObject\ChannelStatus;
use DateTimeImmutable;

final readonly class RegistrationStatusView
{
    public function __construct(
        public ChannelStatus $status,
        public int $requesterAccountId,
        public ?string $rejectionReason,
        public ?string $rejectedByNickname,
        public ?DateTimeImmutable $rejectedAt,
        public ?DateTimeImmutable $restrictedUntil,
    ) {}
}
