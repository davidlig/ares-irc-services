<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Read;

use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;

use function strtolower;

final readonly class ReadMemoHandler implements ReadMemoHandlerInterface
{
    public function __construct(
        private MemoUserAccountPort $userAccountPort,
        private MemoChannelPort $channelPort,
        private MemoRepositoryInterface $memoRepository,
    ) {}

    public function handle(ReadMemo $command): ReadMemoResult
    {
        if (null !== $command->channelName) {
            $channel = $this->channelPort->findChannelByName(strtolower($command->channelName));
            if (null === $channel) {
                return ReadMemoResult::channelNotRegistered($command->channelName);
            }

            if (!$this->channelPort->canReadChannelMemos($channel->id, $command->senderNickId)) {
                return ReadMemoResult::accessDenied($channel->name);
            }
            $memo = $this->memoRepository->findByTargetChannelAndIndex($channel->id, $command->index);
        } else {
            $memo = $this->memoRepository->findByTargetNickAndIndex($command->senderNickId, $command->index);
        }

        if (null === $memo) {
            return ReadMemoResult::notFound($command->index);
        }

        $memo->markAsRead($command->occurredAt);
        $this->memoRepository->save($memo);

        $from = $this->userAccountPort->findNicknameById($memo->getSenderNickId()) ?? (string) $memo->getSenderNickId();

        return ReadMemoResult::success($command->index, $from, $memo->getMessage(), $memo->getCreatedAt());
    }
}
