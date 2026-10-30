<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Del;

use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Domain\Entity\Memo;

use function strtolower;

final readonly class DelMemoHandler implements DelMemoHandlerInterface
{
    public function __construct(
        private MemoChannelPort $channelPort,
        private MemoRepositoryInterface $memoRepository,
    ) {}

    public function handle(DelMemo $command): DelMemoResult
    {
        if (null !== $command->channelName) {
            $channel = $this->channelPort->findChannelByName(strtolower($command->channelName));
            if (null === $channel) {
                return DelMemoResult::channelNotRegistered($command->channelName);
            }

            if (!$this->channelPort->canManageChannelMemos($channel->id, $command->senderNickId)) {
                return DelMemoResult::accessDenied($channel->name);
            }
            if ($command->all) {
                return $this->deleteInbox($this->memoRepository->findByTargetChannel($channel->id));
            }
            $memo = $this->memoRepository->findByTargetChannelAndIndex($channel->id, $command->index);
        } else {
            if ($command->all) {
                return $this->deleteInbox($this->memoRepository->findByTargetNick($command->senderNickId));
            }
            $memo = $this->memoRepository->findByTargetNickAndIndex($command->senderNickId, $command->index);
        }

        if (null === $memo) {
            return DelMemoResult::notFound($command->index);
        }

        $this->memoRepository->delete($memo);

        return DelMemoResult::deleted($command->index);
    }

    /** @param Memo[] $memos */
    private function deleteInbox(array $memos): DelMemoResult
    {
        if ([] === $memos) {
            return DelMemoResult::empty();
        }
        foreach ($memos as $memo) {
            $this->memoRepository->delete($memo);
        }

        return DelMemoResult::allDeleted();
    }
}
