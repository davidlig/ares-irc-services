<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\ApproveChannelRegistration;

final readonly class ApproveChannelRegistrationResult
{
    private function __construct(
        public ApproveChannelRegistrationOutcome $outcome,
        public string $channelName = '',
        public string $founderNickname = '',
    ) {}

    public static function approved(string $channelName, string $founderNickname): self
    {
        return new self(ApproveChannelRegistrationOutcome::Approved, $channelName, $founderNickname);
    }

    public static function invalidChannel(string $channelName): self
    {
        return new self(ApproveChannelRegistrationOutcome::InvalidChannel, $channelName);
    }

    public static function noPendingRequest(string $channelName): self
    {
        return new self(ApproveChannelRegistrationOutcome::NoPendingRequest, $channelName);
    }

    public static function alreadyRegisteredElsewhere(string $channelName): self
    {
        return new self(ApproveChannelRegistrationOutcome::AlreadyRegisteredElsewhere, $channelName);
    }

    public static function alreadyApproved(string $channelName, string $founderNickname): self
    {
        return new self(ApproveChannelRegistrationOutcome::AlreadyApproved, $channelName, $founderNickname);
    }

    public static function alreadyDenied(string $channelName): self
    {
        return new self(ApproveChannelRegistrationOutcome::AlreadyDenied, $channelName);
    }

    public static function registrationFailed(string $channelName): self
    {
        return new self(ApproveChannelRegistrationOutcome::RegistrationFailed, $channelName);
    }
}
