<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Service\ChannelForbiddenService;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
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
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 10],
            ChannelSyncedEvent::class => ['onChannelSynced', 10],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $channelName = (string) $event->channel;

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

    public function onChannelSynced(ChannelSyncedEvent $event): void
    {
        if (!$event->channelSetupApplicable) {
            return;
        }

        $channelName = $event->channel->name->value;

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
