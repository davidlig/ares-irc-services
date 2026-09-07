<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Send;

use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoThrottlePort;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Domain\Entity\Memo;
use App\MemoServ\Domain\Exception\MemoDisabledException;

use function str_starts_with;
use function strtolower;

final readonly class SendMemoHandler implements SendMemoHandlerInterface
{
    public function __construct(
        private MemoUserAccountPort $userAccountPort,
        private MemoChannelPort $channelPort,
        private MemoRepositoryInterface $memoRepository,
        private MemoIgnoreRepositoryInterface $memoIgnoreRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
        private MemoThrottlePort $throttle,
        private int $maxMemosPerNick,
        private int $maxMemosPerChannel,
        private int $sendMinIntervalSeconds,
    ) {}

    public function handle(SendMemo $command): SendMemoResult
    {
        $remaining = $this->throttle->getRemainingCooldownSeconds($command->senderUid, $this->sendMinIntervalSeconds);
        if ($remaining > 0) {
            return SendMemoResult::throttled($remaining);
        }

        if (str_starts_with($command->target, '#')) {
            return $this->sendToChannel($command);
        }

        return $this->sendToNick($command);
    }

    private function sendToChannel(SendMemo $command): SendMemoResult
    {
        $channel = $this->channelPort->findChannelByName(strtolower($command->target));
        if (null === $channel) {
            return SendMemoResult::channelNotRegistered($command->target);
        }

        if (!$this->memoSettingsRepository->isEnabledForChannel($channel->id)) {
            throw MemoDisabledException::forTarget($command->target);
        }

        $ignored = null !== $this->memoIgnoreRepository->findByTargetChannelAndIgnored($channel->id, $command->senderNickId);
        if ($ignored) {
            return SendMemoResult::ignored();
        }

        $count = $this->memoRepository->countByTargetChannel($channel->id);
        if ($count >= $this->maxMemosPerChannel) {
            return SendMemoResult::limitReached($command->target);
        }

        $memo = new Memo(null, $channel->id, $command->senderNickId, $command->message);
        $this->memoRepository->save($memo);
        $this->throttle->recordSend($command->senderUid);

        return SendMemoResult::sentToChannel($channel->name);
    }

    private function sendToNick(SendMemo $command): SendMemoResult
    {
        $recipient = $this->userAccountPort->findAccountByNick($command->target);
        if (null === $recipient) {
            return SendMemoResult::nickNotRegistered($command->target);
        }

        if ($recipient->id === $command->senderNickId) {
            return SendMemoResult::cannotSendToSelf();
        }

        if (!$this->memoSettingsRepository->isEnabledForNick($recipient->id)) {
            throw MemoDisabledException::forTarget($command->target);
        }

        $ignored = null !== $this->memoIgnoreRepository->findByTargetNickAndIgnored($recipient->id, $command->senderNickId);
        if ($ignored) {
            return SendMemoResult::ignored();
        }

        $count = $this->memoRepository->countByTargetNick($recipient->id);
        if ($count >= $this->maxMemosPerNick) {
            return SendMemoResult::limitReached($command->target);
        }

        $memo = new Memo($recipient->id, null, $command->senderNickId, $command->message);
        $this->memoRepository->save($memo);
        $this->throttle->recordSend($command->senderUid);

        $unread = $this->memoRepository->countUnreadByTargetNick($recipient->id);

        return SendMemoResult::sentToNick($recipient->id, $recipient->nickname, $unread, $recipient->language);
    }
}
