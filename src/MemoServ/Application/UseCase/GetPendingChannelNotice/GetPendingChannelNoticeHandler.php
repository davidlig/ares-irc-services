<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\GetPendingChannelNotice;

use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;

use function strtolower;

final readonly class GetPendingChannelNoticeHandler
{
    public function __construct(
        private MemoChannelPort $channelPort,
        private MemoUserAccountPort $userAccountPort,
        private MemoRepositoryInterface $memoRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {}

    public function handle(GetPendingChannelNotice $query): GetPendingChannelNoticeResult
    {
        $channel = $this->channelPort->findChannelByName(strtolower($query->channelName));
        if (null === $channel || !$this->memoSettingsRepository->isEnabledForChannel($channel->id)) {
            return GetPendingChannelNoticeResult::noNotice();
        }

        $unread = $this->memoRepository->countUnreadByTargetChannel($channel->id);
        if (0 === $unread) {
            return GetPendingChannelNoticeResult::noNotice();
        }

        $account = $this->userAccountPort->findAccountByNick($query->nickname);
        if (null === $account || !$query->isIdentified || !$this->channelPort->canReadChannelMemos($channel->id, $account->id)) {
            return GetPendingChannelNoticeResult::noNotice();
        }

        return GetPendingChannelNoticeResult::pendingMemos(
            uid: $query->uid,
            channelName: $query->channelName,
            unreadCount: $unread,
            language: $account->language,
        );
    }
}
