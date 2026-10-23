<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

final readonly class SaregisterChannelResult
{
    private function __construct(
        public SaregisterChannelOutcome $outcome,
        public string $channelName,
        public string $founderNickname,
    ) {}

    public static function registered(string $channelName, string $founderNickname): self
    {
        return new self(SaregisterChannelOutcome::Registered, $channelName, $founderNickname);
    }

    public static function alreadyRegistered(string $channelName, string $founderNickname): self
    {
        return new self(SaregisterChannelOutcome::AlreadyRegistered, $channelName, $founderNickname);
    }

    public static function pendingDeletion(string $channelName, string $founderNickname): self
    {
        return new self(SaregisterChannelOutcome::PendingDeletion, $channelName, $founderNickname);
    }

    public static function founderNotFound(string $channelName, string $founderNickname): self
    {
        return new self(SaregisterChannelOutcome::FounderNotFound, $channelName, $founderNickname);
    }

    public static function founderInactive(string $channelName, string $founderNickname): self
    {
        return new self(SaregisterChannelOutcome::FounderInactive, $channelName, $founderNickname);
    }
}
