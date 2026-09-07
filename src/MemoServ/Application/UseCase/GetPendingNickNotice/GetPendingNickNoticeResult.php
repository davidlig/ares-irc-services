<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\GetPendingNickNotice;

final readonly class GetPendingNickNoticeResult
{
    private function __construct(
        public GetPendingNickNoticeOutcome $outcome,
        public string $uid = '',
        public int $unreadCount = 0,
        public string $language = '',
    ) {}

    public static function noNotice(): self
    {
        return new self(GetPendingNickNoticeOutcome::NoNotice);
    }

    public static function pendingMemos(string $uid, int $unreadCount, string $language): self
    {
        return new self(
            outcome: GetPendingNickNoticeOutcome::PendingMemos,
            uid: $uid,
            unreadCount: $unreadCount,
            language: $language,
        );
    }
}
