<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ShowInfo;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function strtolower;

final readonly class ShowChannelInfoHandler implements ShowChannelInfoHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanUserAccountPort $accounts,
        private ChannelAccessRepositoryInterface $access,
        private int $dropGraceDays = 7,
    ) {}

    public function handle(ShowChannelInfo $query): ?ChannelInfoView
    {
        $channel = $this->channels->findByChannelName(strtolower($query->channelName));
        if (null === $channel) {
            return null;
        }

        $privateInfoDenied = $this->isPrivateInfoDenied($channel, $query);

        $founderId = $channel->getFounderNickId();
        $founder = $this->accounts->findAccountById($founderId);
        $successorId = $channel->getSuccessorNickId();
        $successor = null !== $successorId ? $this->accounts->findAccountById($successorId) : null;

        return new ChannelInfoView(
            founderAccountId: $founderId,
            founderName: null !== $founder ? $founder->nickname : (string) $founderId,
            successorName: null !== $successorId ? (null !== $successor ? $successor->nickname : (string) $successorId) : null,
            description: $channel->getDescription(),
            createdAt: $channel->getCreatedAt(),
            lastUsedAt: $channel->getLastUsedAt(),
            url: $channel->getUrl(),
            email: $channel->getEmail(),
            topic: $channel->getTopic(),
            lastTopicSetByNick: $channel->getLastTopicSetByNick(),
            topicLock: $channel->isTopicLock(),
            mlockActive: $channel->isMlockActive(),
            mlock: $channel->getMlock(),
            secure: $channel->isSecure(),
            ircopOnly: $channel->isIrcopOnly(),
            noExpire: $channel->isNoExpire(),
            forbidden: $channel->isForbidden(),
            forbiddenReason: $channel->getForbiddenReason(),
            pendingDeletion: $channel->isPendingDeletion(),
            pendingDeletionAt: $channel->getPendingDeletionAt(),
            pendingDeletionUntil: $channel->getPendingDeletionExpiresAt($this->dropGraceDays),
            suspended: $channel->isSuspended(),
            suspendedReason: $channel->getSuspendedReason(),
            suspendedUntil: $channel->getSuspendedUntil(),
            privateInfoDenied: $privateInfoDenied,
        );
    }

    private function isPrivateInfoDenied(RegisteredChannel $channel, ShowChannelInfo $query): bool
    {
        if (!$channel->isPrivate() || $query->requesterIsOper) {
            return false;
        }

        $accountId = $query->requesterAccountId;
        if (!$query->requesterIsIdentified || null === $accountId) {
            return true;
        }
        if ($channel->getFounderNickId() === $accountId) {
            return false;
        }

        return null === $this->access->findByChannelAndNick($channel->getId(), $accountId);
    }
}
