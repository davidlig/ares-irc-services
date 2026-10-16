<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\DropChannelRegistration;

final readonly class DropChannelRegistrationResult
{
    private function __construct(
        public DropChannelRegistrationOutcome $outcome,
        public string $channelName,
    ) {}

    public static function dropped(string $channelName): self
    {
        return new self(DropChannelRegistrationOutcome::Dropped, $channelName);
    }

    public static function invalidChannel(string $channelName): self
    {
        return new self(DropChannelRegistrationOutcome::InvalidChannel, $channelName);
    }

    public static function noRequest(string $channelName): self
    {
        return new self(DropChannelRegistrationOutcome::NoRequest, $channelName);
    }
}
