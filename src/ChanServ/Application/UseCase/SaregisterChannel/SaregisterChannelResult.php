<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

final readonly class SaregisterChannelResult
{
    private function __construct(
        public SaregisterChannelOutcome $outcome,
        public ?string $channelName = null,
        public ?string $targetNickname = null,
    ) {}

    public static function registered(string $channelName, string $targetNickname): self
    {
        return new self(SaregisterChannelOutcome::Registered, channelName: $channelName, targetNickname: $targetNickname);
    }

    public static function invalidChannelName(string $channelName): self
    {
        return new self(SaregisterChannelOutcome::InvalidChannelName, channelName: $channelName);
    }

    public static function alreadyRegistered(string $channelName): self
    {
        return new self(SaregisterChannelOutcome::AlreadyRegistered, channelName: $channelName);
    }

    public static function pendingDeletion(string $channelName): self
    {
        return new self(SaregisterChannelOutcome::PendingDeletion, channelName: $channelName);
    }

    public static function targetNotFound(string $targetNickname): self
    {
        return new self(SaregisterChannelOutcome::TargetNotFound, targetNickname: $targetNickname);
    }

    public static function targetNotRegistered(string $targetNickname): self
    {
        return new self(SaregisterChannelOutcome::TargetNotRegistered, targetNickname: $targetNickname);
    }

    public static function targetSuspended(string $targetNickname): self
    {
        return new self(SaregisterChannelOutcome::TargetSuspended, targetNickname: $targetNickname);
    }
}
