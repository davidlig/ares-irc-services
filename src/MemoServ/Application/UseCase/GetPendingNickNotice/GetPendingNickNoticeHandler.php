<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\GetPendingNickNotice;

use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;

final readonly class GetPendingNickNoticeHandler
{
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
        private MemoUserAccountPort $userAccountPort,
    ) {}

    public function handle(GetPendingNickNotice $query): GetPendingNickNoticeResult
    {
        $unread = $this->memoRepository->countUnreadByTargetNick($query->nickId);
        if (0 === $unread) {
            return GetPendingNickNoticeResult::noNotice();
        }

        return GetPendingNickNoticeResult::pendingMemos(
            uid: $query->uid,
            unreadCount: $unread,
            language: $this->userAccountPort->getLanguage($query->nickId),
        );
    }
}
