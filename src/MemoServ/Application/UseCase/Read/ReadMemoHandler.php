<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Read;

use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use LogicException;

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

        $from = $memo->getSenderName();
        if (null === $from) {
            $senderNickId = $memo->getSenderNickId();
            if (null === $senderNickId) {
                throw new LogicException('Memo sender representation is missing.');
            }
            $from = $this->userAccountPort->findNicknameById($senderNickId) ?? (string) $senderNickId;
        }

        $memo->markAsRead($command->occurredAt);
        $this->memoRepository->save($memo);

        return ReadMemoResult::success($command->index, $from, $memo->getMessage(), $memo->getCreatedAt());
    }
}
