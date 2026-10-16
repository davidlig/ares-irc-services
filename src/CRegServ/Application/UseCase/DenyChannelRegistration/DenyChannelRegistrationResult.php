<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DenyChannelRegistration;

final readonly class DenyChannelRegistrationResult
{
    private function __construct(
        public DenyChannelRegistrationOutcome $outcome,
        public string $channelName = '',
        public string $reason = '',
    ) {}

    public static function denied(string $channelName, string $reason): self
    {
        return new self(DenyChannelRegistrationOutcome::Denied, $channelName, $reason);
    }

    public static function invalidChannel(string $channelName): self
    {
        return new self(DenyChannelRegistrationOutcome::InvalidChannel, $channelName);
    }

    public static function noPendingRequest(string $channelName): self
    {
        return new self(DenyChannelRegistrationOutcome::NoPendingRequest, $channelName);
    }

    public static function alreadyApproved(string $channelName): self
    {
        return new self(DenyChannelRegistrationOutcome::AlreadyApproved, $channelName);
    }

    public static function alreadyDenied(string $channelName): self
    {
        return new self(DenyChannelRegistrationOutcome::AlreadyDenied, $channelName);
    }
}
