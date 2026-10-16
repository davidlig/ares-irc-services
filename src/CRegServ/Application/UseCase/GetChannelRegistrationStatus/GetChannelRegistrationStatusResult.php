<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\GetChannelRegistrationStatus;

use DateTimeImmutable;

final readonly class GetChannelRegistrationStatusResult
{
    private function __construct(
        public GetChannelRegistrationStatusOutcome $outcome,
        public ?DateTimeImmutable $requestedAt = null,
        public ?string $deniedReason = null,
        public ?DateTimeImmutable $deniedAt = null,
    ) {}

    public static function registered(): self
    {
        return new self(GetChannelRegistrationStatusOutcome::Registered);
    }

    public static function pendingDeletion(): self
    {
        return new self(GetChannelRegistrationStatusOutcome::PendingDeletion);
    }

    public static function pending(DateTimeImmutable $requestedAt): self
    {
        return new self(GetChannelRegistrationStatusOutcome::Pending, requestedAt: $requestedAt);
    }

    public static function denied(?string $deniedReason, ?DateTimeImmutable $deniedAt): self
    {
        return new self(
            GetChannelRegistrationStatusOutcome::Denied,
            deniedReason: $deniedReason,
            deniedAt: $deniedAt,
        );
    }

    public static function noRequest(): self
    {
        return new self(GetChannelRegistrationStatusOutcome::NoRequest);
    }
}
