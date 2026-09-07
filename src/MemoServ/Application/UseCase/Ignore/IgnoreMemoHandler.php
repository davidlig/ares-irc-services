<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Ignore;

use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Domain\Entity\MemoIgnore;

use function strtolower;

final readonly class IgnoreMemoHandler implements IgnoreMemoHandlerInterface
{
    public function __construct(
        private MemoUserAccountPort $userAccountPort,
        private MemoChannelPort $channelPort,
        private MemoIgnoreRepositoryInterface $memoIgnoreRepository,
        private int $ignoreListLimitNick,
        private int $ignoreListLimitChannel,
    ) {}

    public function handle(IgnoreMemo $command): IgnoreMemoResult
    {
        $channel = null;
        if (null !== $command->channelName) {
            $channel = $this->channelPort->findChannelByName(strtolower($command->channelName));
            if (null === $channel) {
                return IgnoreMemoResult::channelNotRegistered($command->channelName);
            }

            if (IgnoreMemoAction::List !== $command->action) {
                $this->channelPort->requireManageAccess($channel->id, $command->senderNickId, $command->channelName, 'IGNORE');
            }
        }

        return match ($command->action) {
            IgnoreMemoAction::Add => $this->handleAdd($command, $channel),
            IgnoreMemoAction::Del => $this->handleDel($command, $channel),
            IgnoreMemoAction::List => $this->handleList($command, $channel),
        };
    }

    private function handleAdd(IgnoreMemo $command, ?MemoChannelView $channel): IgnoreMemoResult
    {
        $target = $this->userAccountPort->findAccountByNick((string) $command->targetNick);
        if (null === $target) {
            return IgnoreMemoResult::nickNotRegistered((string) $command->targetNick);
        }

        if (null !== $channel) {
            if (null !== $this->memoIgnoreRepository->findByTargetChannelAndIgnored($channel->id, $target->id)) {
                return IgnoreMemoResult::alreadyIgnored($target->nickname);
            }

            if ($this->memoIgnoreRepository->countByTargetChannel($channel->id) >= $this->ignoreListLimitChannel) {
                return IgnoreMemoResult::limitReached($channel->name);
            }

            $this->memoIgnoreRepository->save(new MemoIgnore(null, $channel->id, $target->id));

            return IgnoreMemoResult::addedChannel($channel->name, $target->nickname);
        }

        if ($target->id === $command->senderNickId) {
            return IgnoreMemoResult::cannotIgnoreSelf();
        }

        if (null !== $this->memoIgnoreRepository->findByTargetNickAndIgnored($command->senderNickId, $target->id)) {
            return IgnoreMemoResult::alreadyIgnored($target->nickname);
        }

        if ($this->memoIgnoreRepository->countByTargetNick($command->senderNickId) >= $this->ignoreListLimitNick) {
            return IgnoreMemoResult::limitReached();
        }

        $this->memoIgnoreRepository->save(new MemoIgnore($command->senderNickId, null, $target->id));

        return IgnoreMemoResult::addedNick($target->nickname);
    }

    private function handleDel(IgnoreMemo $command, ?MemoChannelView $channel): IgnoreMemoResult
    {
        $target = $this->userAccountPort->findAccountByNick((string) $command->targetNick);
        if (null === $target) {
            return IgnoreMemoResult::nickNotRegistered((string) $command->targetNick);
        }

        if (null !== $channel) {
            $existing = $this->memoIgnoreRepository->findByTargetChannelAndIgnored($channel->id, $target->id);
            if (null === $existing) {
                return IgnoreMemoResult::notIgnored($target->nickname);
            }

            $this->memoIgnoreRepository->delete($existing);

            return IgnoreMemoResult::deletedChannel($channel->name, $target->nickname);
        }

        $existing = $this->memoIgnoreRepository->findByTargetNickAndIgnored($command->senderNickId, $target->id);
        if (null === $existing) {
            return IgnoreMemoResult::notIgnored($target->nickname);
        }

        $this->memoIgnoreRepository->delete($existing);

        return IgnoreMemoResult::deletedNick($target->nickname);
    }

    private function handleList(IgnoreMemo $command, ?MemoChannelView $channel): IgnoreMemoResult
    {
        if (null !== $channel) {
            $list = $this->memoIgnoreRepository->listByTargetChannel($channel->id);
            $nicks = [];
            foreach ($list as $item) {
                $nicks[] = $this->userAccountPort->findNicknameById($item->getIgnoredNickId()) ?? (string) $item->getIgnoredNickId();
            }

            return IgnoreMemoResult::listChannel($channel->name, $nicks);
        }

        $list = $this->memoIgnoreRepository->listByTargetNick($command->senderNickId);
        $nicks = [];
        foreach ($list as $item) {
            $nicks[] = $this->userAccountPort->findNicknameById($item->getIgnoredNickId()) ?? (string) $item->getIgnoredNickId();
        }

        return IgnoreMemoResult::listNick($nicks);
    }
}
