<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Send;

final readonly class SendMemoResult
{
    private function __construct(
        public SendMemoOutcome $outcome,
        public ?string $targetName = null,
        public ?int $targetNickId = null,
        public ?int $unreadCount = null,
        public ?string $recipientLanguage = null,
        public int $cooldownRemainingSeconds = 0,
    ) {}

    public static function sentToNick(int $targetNickId, string $targetNick, int $unreadCount, string $recipientLanguage): self
    {
        return new self(
            outcome: SendMemoOutcome::SentToNick,
            targetName: $targetNick,
            targetNickId: $targetNickId,
            unreadCount: $unreadCount,
            recipientLanguage: $recipientLanguage,
        );
    }

    public static function sentToChannel(string $channelName): self
    {
        return new self(
            outcome: SendMemoOutcome::SentToChannel,
            targetName: $channelName,
        );
    }

    public static function throttled(int $remainingSeconds): self
    {
        return new self(
            outcome: SendMemoOutcome::Throttled,
            cooldownRemainingSeconds: $remainingSeconds,
        );
    }

    public static function cannotSendToSelf(): self
    {
        return new self(outcome: SendMemoOutcome::CannotSendToSelf);
    }

    public static function nickNotRegistered(string $targetNick): self
    {
        return new self(
            outcome: SendMemoOutcome::NickNotRegistered,
            targetName: $targetNick,
        );
    }

    public static function channelNotRegistered(string $channelName): self
    {
        return new self(
            outcome: SendMemoOutcome::ChannelNotRegistered,
            targetName: $channelName,
        );
    }

    public static function ignored(): self
    {
        return new self(outcome: SendMemoOutcome::Ignored);
    }

    public static function limitReached(string $targetName): self
    {
        return new self(
            outcome: SendMemoOutcome::LimitReached,
            targetName: $targetName,
        );
    }
}
