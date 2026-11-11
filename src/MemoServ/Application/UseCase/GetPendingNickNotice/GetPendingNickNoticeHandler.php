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
        private ?BurstMemoNoticeCache $cache = null,
    ) {}

    public function handle(GetPendingNickNotice $query): GetPendingNickNoticeResult
    {
        if (null !== $this->cache && $this->cache->hasActiveBatch() && $this->cache->includesNickId($query->nickId)) {
            $unread = $this->cache->countFor($query->nickId);
            if (null === $unread) {
                $this->cache->replaceActiveCounts($this->memoRepository->countUnreadByTargetNickIds($this->cache->activeNickIds()));
                $unread = $this->cache->countFor($query->nickId) ?? 0;
            }
        } else {
            $unread = $this->memoRepository->countUnreadByTargetNick($query->nickId);
        }
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
