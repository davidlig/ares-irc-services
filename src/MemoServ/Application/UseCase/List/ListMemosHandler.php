<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\List;

use App\MemoServ\Application\Model\MemoListItem;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;

use function strtolower;

final readonly class ListMemosHandler implements ListMemosHandlerInterface
{
    public function __construct(
        private MemoUserAccountPort $userAccountPort,
        private MemoChannelPort $channelPort,
        private MemoRepositoryInterface $memoRepository,
    ) {}

    public function handle(ListMemos $command): ListMemosResult
    {
        if (null !== $command->channelName) {
            $channel = $this->channelPort->findChannelByName(strtolower($command->channelName));
            if (null === $channel) {
                return ListMemosResult::channelNotRegistered($command->channelName);
            }

            if (!$this->channelPort->canReadChannelMemos($channel->id, $command->senderNickId)) {
                return ListMemosResult::accessDenied($channel->name);
            }
            $memos = $this->memoRepository->findByTargetChannel($channel->id);
            $targetLabel = $command->channelName;
        } else {
            $memos = $this->memoRepository->findByTargetNick($command->senderNickId);
            $targetLabel = $command->senderNickName;
        }

        if ([] === $memos) {
            return ListMemosResult::empty($targetLabel);
        }

        $items = [];
        $index = 1;
        foreach ($memos as $memo) {
            $senderDisplay = $this->userAccountPort->findNicknameById($memo->getSenderNickId()) ?? (string) $memo->getSenderNickId();
            $items[] = new MemoListItem($index, $senderDisplay, $memo->getCreatedAt(), $memo->getMessage(), $memo->isRead());
            ++$index;
        }

        return ListMemosResult::success($targetLabel, $items);
    }
}
