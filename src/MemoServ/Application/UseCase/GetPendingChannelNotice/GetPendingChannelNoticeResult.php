<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\GetPendingChannelNotice;

final readonly class GetPendingChannelNoticeResult
{
    private function __construct(
        public GetPendingChannelNoticeOutcome $outcome,
        public string $uid = '',
        public string $channelName = '',
        public int $unreadCount = 0,
        public string $language = '',
    ) {}

    public static function noNotice(): self
    {
        return new self(GetPendingChannelNoticeOutcome::NoNotice);
    }

    public static function pendingMemos(
        string $uid,
        string $channelName,
        int $unreadCount,
        string $language,
    ): self {
        return new self(
            outcome: GetPendingChannelNoticeOutcome::PendingMemos,
            uid: $uid,
            channelName: $channelName,
            unreadCount: $unreadCount,
            language: $language,
        );
    }
}
