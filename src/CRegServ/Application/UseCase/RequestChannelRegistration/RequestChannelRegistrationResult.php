<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\RequestChannelRegistration;

final readonly class RequestChannelRegistrationResult
{
    private function __construct(
        public RequestChannelRegistrationOutcome $outcome,
        public ?int $remainingMinutes = null,
    ) {}

    public static function requested(): self
    {
        return new self(RequestChannelRegistrationOutcome::Requested);
    }

    public static function invalidChannel(): self
    {
        return new self(RequestChannelRegistrationOutcome::InvalidChannel);
    }

    public static function channelTooLong(): self
    {
        return new self(RequestChannelRegistrationOutcome::ChannelTooLong);
    }

    public static function descriptionTooLong(): self
    {
        return new self(RequestChannelRegistrationOutcome::DescriptionTooLong);
    }

    public static function alreadyRegistered(): self
    {
        return new self(RequestChannelRegistrationOutcome::AlreadyRegistered);
    }

    public static function duplicateRequest(): self
    {
        return new self(RequestChannelRegistrationOutcome::DuplicateRequest);
    }

    public static function requestDenied(): self
    {
        return new self(RequestChannelRegistrationOutcome::RequestDenied);
    }

    public static function throttled(int $remainingMinutes): self
    {
        return new self(RequestChannelRegistrationOutcome::Throttled, remainingMinutes: $remainingMinutes);
    }
}
