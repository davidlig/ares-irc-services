<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\List;

use App\MemoServ\Application\Model\MemoListItem;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;

use function mb_strlen;
use function mb_substr;
use function str_replace;
use function strtolower;

final readonly class ListMemosHandler implements ListMemosHandlerInterface
{
    private const int PREVIEW_MAX_LENGTH = 50;

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

            $this->channelPort->requireReadAccess($channel->id, $command->senderNickId, $command->channelName, 'LIST');
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
            $preview = self::preview($memo->getMessage());
            $items[] = new MemoListItem($index, $senderDisplay, $memo->getCreatedAt(), $preview, $memo->isRead());
            ++$index;
        }

        return ListMemosResult::success($targetLabel, $items);
    }

    private static function preview(string $message): string
    {
        $message = str_replace(["\r", "\n"], ' ', $message);
        if (mb_strlen($message) <= self::PREVIEW_MAX_LENGTH) {
            return $message;
        }

        return mb_substr($message, 0, self::PREVIEW_MAX_LENGTH) . '…';
    }
}
