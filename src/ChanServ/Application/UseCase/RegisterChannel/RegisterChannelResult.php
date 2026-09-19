<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RegisterChannel;

final readonly class RegisterChannelResult
{
    private function __construct(
        public RegisterChannelOutcome $outcome,
        public ?int $remainingMinutes = null,
        public ?int $maximumChannels = null,
    ) {}

    public static function registered(): self
    {
        return new self(RegisterChannelOutcome::Registered);
    }

    public static function notIdentified(): self
    {
        return new self(RegisterChannelOutcome::NotIdentified);
    }

    public static function pendingDeletion(): self
    {
        return new self(RegisterChannelOutcome::PendingDeletion);
    }

    public static function channelNotOnNetwork(): self
    {
        return new self(RegisterChannelOutcome::ChannelNotOnNetwork);
    }

    public static function insufficientChannelRank(): self
    {
        return new self(RegisterChannelOutcome::InsufficientChannelRank);
    }

    public static function throttled(int $remainingMinutes): self
    {
        return new self(RegisterChannelOutcome::Throttled, remainingMinutes: $remainingMinutes);
    }

    public static function founderLimitExceeded(int $maximumChannels): self
    {
        return new self(RegisterChannelOutcome::FounderLimitExceeded, maximumChannels: $maximumChannels);
    }
}
