<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Enable;

final readonly class EnableMemosResult
{
    private function __construct(
        public EnableMemosOutcome $outcome,
        public ?string $channelName = null,
    ) {}

    public static function enabledNick(): self
    {
        return new self(EnableMemosOutcome::EnabledNick);
    }

    public static function enabledChannel(string $channelName): self
    {
        return new self(
            outcome: EnableMemosOutcome::EnabledChannel,
            channelName: $channelName,
        );
    }

    public static function alreadyEnabledNick(): self
    {
        return new self(EnableMemosOutcome::AlreadyEnabledNick);
    }

    public static function alreadyEnabledChannel(string $channelName): self
    {
        return new self(
            outcome: EnableMemosOutcome::AlreadyEnabledChannel,
            channelName: $channelName,
        );
    }

    public static function channelNotRegistered(string $channelName): self
    {
        return new self(
            outcome: EnableMemosOutcome::ChannelNotRegistered,
            channelName: $channelName,
        );
    }

    public static function founderOnly(string $channelName): self
    {
        return new self(
            outcome: EnableMemosOutcome::FounderOnly,
            channelName: $channelName,
        );
    }
}
