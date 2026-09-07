<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Disable;

final readonly class DisableMemosResult
{
    private function __construct(
        public DisableMemosOutcome $outcome,
        public ?string $channelName = null,
    ) {}

    public static function disabledNick(): self
    {
        return new self(DisableMemosOutcome::DisabledNick);
    }

    public static function disabledChannel(string $channelName): self
    {
        return new self(
            outcome: DisableMemosOutcome::DisabledChannel,
            channelName: $channelName,
        );
    }

    public static function alreadyDisabledNick(): self
    {
        return new self(DisableMemosOutcome::AlreadyDisabledNick);
    }

    public static function alreadyDisabledChannel(string $channelName): self
    {
        return new self(
            outcome: DisableMemosOutcome::AlreadyDisabledChannel,
            channelName: $channelName,
        );
    }

    public static function channelNotRegistered(string $channelName): self
    {
        return new self(
            outcome: DisableMemosOutcome::ChannelNotRegistered,
            channelName: $channelName,
        );
    }

    public static function founderOnly(string $channelName): self
    {
        return new self(
            outcome: DisableMemosOutcome::FounderOnly,
            channelName: $channelName,
        );
    }
}
