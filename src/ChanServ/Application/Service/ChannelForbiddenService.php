<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelForbiddenEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnforbiddenEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;

use function sprintf;

readonly class ChannelForbiddenService
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanDropService $dropService,
        private ChanNetworkActions $channelActions,
        private ChanServEventPublisher $eventPublisher,
        private ChanServActivitySink $logger,
    ) {}

    public function forbid(
        string $channelName,
        string $reason,
        string $operatorNick,
        string $defaultLanguage = 'en',
    ): RegisteredChannel {
        $existing = $this->channelRepository->findByChannelName($channelName);

        if (null !== $existing && $existing->isForbidden()) {
            $existing->updateForbiddenReason($reason);
            $this->channelRepository->save($existing);

            $this->logger->info(sprintf(
                'ChannelForbidden: Reason updated for %s. Reason: %s. Operator: %s',
                $channelName,
                $reason,
                $operatorNick,
            ));

            $this->eventPublisher->publish(new ChannelForbiddenEvent(
                channelId: $existing->getId(),
                channelName: $existing->getName(),
                channelNameLower: $existing->getNameLower(),
                reason: $reason,
                performedBy: $operatorNick,
            ));

            return $existing;
        }

        if (null !== $existing && !$existing->isForbidden()) {
            $this->dropService->dropChannel($existing, 'forbid', $operatorNick);
            $this->logger->info(sprintf(
                'ChannelForbidden: Dropped existing channel %s before creating forbidden entry',
                $channelName,
            ));
        }

        $forbidden = RegisteredChannel::createForbidden($channelName, $reason);
        $this->channelRepository->save($forbidden);

        $this->logger->info(sprintf(
            'ChannelForbidden: Channel %s has been forbidden. Reason: %s. Operator: %s',
            $channelName,
            $reason,
            $operatorNick,
        ));

        $this->eventPublisher->publish(new ChannelForbiddenEvent(
            channelId: $forbidden->getId(),
            channelName: $forbidden->getName(),
            channelNameLower: $forbidden->getNameLower(),
            reason: $reason,
            performedBy: $operatorNick,
        ));

        $this->enforceForbiddenChannel($forbidden->getName());

        return $forbidden;
    }

    public function unforbid(string $channelName, string $operatorNick): bool
    {
        $channel = $this->channelRepository->findByChannelName($channelName);

        if (null === $channel || !$channel->isForbidden()) {
            return false;
        }

        $this->channelRepository->delete($channel);

        $this->logger->info(sprintf(
            'ChannelForbidden: Channel %s has been unforbidden by %s',
            $channelName,
            $operatorNick,
        ));

        $this->eventPublisher->publish(new ChannelUnforbiddenEvent(
            channelName: $channel->getName(),
            channelNameLower: $channel->getNameLower(),
            performedBy: $operatorNick,
        ));

        return true;
    }

    public function enforceForbiddenChannel(string $channelName): void
    {
        if (!$this->channelActions->isChannelOnNetwork($channelName)) {
            $this->logger->debug(sprintf(
                'ChannelForbidden: Channel %s not found on network, skipping enforcement',
                $channelName,
            ));

            return;
        }

        $timestamp = $this->channelActions->getChannelTimestamp($channelName);
        $this->channelActions->joinChannelAsService($channelName, $timestamp);

        $this->kickAllUsers($channelName);

        $this->channelActions->setChannelModes($channelName, '+ntims', [], $timestamp);

        $this->logger->info(sprintf(
            'ChannelForbidden: Enforced forbidden channel %s (bot joined, users kicked, +ntims set)',
            $channelName,
        ));
    }

    private function kickAllUsers(string $channelName): void
    {
        foreach ($this->channelActions->getChannelMemberUids($channelName) as $uid) {
            $this->channelActions->kickFromChannel(
                $channelName,
                $uid,
                'Forbidden channel',
            );
        }
    }
}
