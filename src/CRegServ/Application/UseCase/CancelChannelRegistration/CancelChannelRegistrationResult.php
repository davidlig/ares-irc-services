<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\CancelChannelRegistration;

final readonly class CancelChannelRegistrationResult
{
    private function __construct(
        public CancelChannelRegistrationOutcome $outcome,
        public string $channel = '',
    ) {}

    public static function cancelled(string $channel): self
    {
        return new self(CancelChannelRegistrationOutcome::Cancelled, $channel);
    }

    public static function invalidChannel(): self
    {
        return new self(CancelChannelRegistrationOutcome::InvalidChannel);
    }

    public static function noRequest(string $channel): self
    {
        return new self(CancelChannelRegistrationOutcome::NoRequest, $channel);
    }

    public static function notOwner(string $channel): self
    {
        return new self(CancelChannelRegistrationOutcome::NotOwner, $channel);
    }

    public static function notPending(string $channel): self
    {
        return new self(CancelChannelRegistrationOutcome::NotPending, $channel);
    }
}
