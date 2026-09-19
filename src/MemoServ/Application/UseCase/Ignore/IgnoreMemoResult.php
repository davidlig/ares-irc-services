<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Ignore;

final readonly class IgnoreMemoResult
{
    /**
     * @param list<string> $ignoredNicks
     */
    private function __construct(
        public IgnoreMemoOutcome $outcome,
        public ?string $targetNick = null,
        public ?string $channelName = null,
        public array $ignoredNicks = [],
    ) {}

    public static function addedNick(string $targetNick): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::AddedNick,
            targetNick: $targetNick,
        );
    }

    public static function addedChannel(string $channelName, string $targetNick): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::AddedChannel,
            targetNick: $targetNick,
            channelName: $channelName,
        );
    }

    public static function deletedNick(string $targetNick): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::DeletedNick,
            targetNick: $targetNick,
        );
    }

    public static function deletedChannel(string $channelName, string $targetNick): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::DeletedChannel,
            targetNick: $targetNick,
            channelName: $channelName,
        );
    }

    /**
     * @param list<string> $ignoredNicks
     */
    public static function listNick(array $ignoredNicks): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::ListNick,
            ignoredNicks: $ignoredNicks,
        );
    }

    /**
     * @param list<string> $ignoredNicks
     */
    public static function listChannel(string $channelName, array $ignoredNicks): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::ListChannel,
            channelName: $channelName,
            ignoredNicks: $ignoredNicks,
        );
    }

    public static function channelNotRegistered(string $channelName): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::ChannelNotRegistered,
            channelName: $channelName,
        );
    }

    public static function nickNotRegistered(string $targetNick): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::NickNotRegistered,
            targetNick: $targetNick,
        );
    }

    public static function accessDenied(string $channelName): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::AccessDenied,
            channelName: $channelName,
        );
    }

    public static function alreadyIgnored(string $targetNick): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::AlreadyIgnored,
            targetNick: $targetNick,
        );
    }

    public static function notIgnored(string $targetNick): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::NotIgnored,
            targetNick: $targetNick,
        );
    }

    public static function limitReached(?string $channelName = null): self
    {
        return new self(
            outcome: IgnoreMemoOutcome::LimitReached,
            channelName: $channelName,
        );
    }
}
