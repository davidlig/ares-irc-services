<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Service;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelForbiddenEvent;
use App\Domain\ChanServ\Event\ChannelUnforbiddenEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

readonly class ChannelForbiddenService
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanDropService $dropService,
        private ChannelServiceActionsPort $channelServiceActions,
        private ChannelLookupPort $channelLookup,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

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

            $this->eventDispatcher->dispatch(new ChannelForbiddenEvent(
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

        $this->eventDispatcher->dispatch(new ChannelForbiddenEvent(
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

        $this->eventDispatcher->dispatch(new ChannelUnforbiddenEvent(
            channelName: $channel->getName(),
            channelNameLower: $channel->getNameLower(),
            performedBy: $operatorNick,
        ));

        return true;
    }

    public function enforceForbiddenChannel(string $channelName): void
    {
        $view = $this->channelLookup->findByChannelName($channelName);

        if (null === $view) {
            $this->logger->debug(sprintf(
                'ChannelForbidden: Channel %s not found on network, skipping enforcement',
                $channelName,
            ));

            return;
        }

        $this->channelServiceActions->joinChannelAsService($channelName, $view->timestamp);

        $this->kickAllUsers($channelName, $view);

        $this->channelServiceActions->setChannelModes($channelName, '+ntims', []);

        $this->logger->info(sprintf(
            'ChannelForbidden: Enforced forbidden channel %s (bot joined, users kicked, +ntims set)',
            $channelName,
        ));
    }

    private function kickAllUsers(string $channelName, ChannelView $view): void
    {
        foreach ($view->members as $member) {
            $this->channelServiceActions->kickFromChannel(
                $channelName,
                $member['uid'],
                'Forbidden channel',
            );
        }
    }
}
