<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\Application\Port\ChannelServiceActionsPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

final readonly class ChanServForbiddenChannelJoinSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelServiceActionsPort $channelServiceActions,
        private ChannelLookupPort $channelLookup,
        private ChannelForbiddenService $forbiddenService,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 10],
            ChannelSynchronizedEvent::class => ['onChannelSynced', 10],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $channelName = $event->channelName;

        $channel = $this->channelRepository->findByChannelName($channelName);

        if (null === $channel || !$channel->isForbidden()) {
            return;
        }

        $this->channelServiceActions->kickFromChannel(
            $channelName,
            (string) $event->uid,
            'Forbidden channel',
        );

        $view = $this->channelLookup->findByChannelName($channelName);
        if (null !== $view) {
            $this->forbiddenService->enforceForbiddenChannel($channelName);
        }

        $this->logger->info(sprintf(
            'ChanServForbiddenChannelJoin: kicked user from forbidden channel %s',
            $channelName,
        ));
    }

    /**
     * Enforce forbidden channels on ChannelSyncedEvent — always runs because
     * forbidden channel enforcement must happen on every sync, not just initial setup.
     */
    public function onChannelSynced(ChannelSynchronizedEvent $event): void
    {
        $channelName = $event->channelName;

        $channel = $this->channelRepository->findByChannelName($channelName);

        if (null === $channel || !$channel->isForbidden()) {
            return;
        }

        $this->forbiddenService->enforceForbiddenChannel($channelName);

        $this->logger->info(sprintf(
            'ChanServForbiddenChannelJoin: enforcing forbidden channel %s on sync',
            $channelName,
        ));
    }
}
