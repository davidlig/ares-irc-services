<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Domain\Event\ChannelModesChangedEvent;
use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Event\ChannelTopicChangedEvent;
use App\Irc\Domain\Event\UserHostChangedEvent;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\Irc\Domain\Event\UserJoinedNetworkEvent;
use App\Irc\Domain\Event\UserLeftChannelEvent;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\Event\UserNickChangedEvent;
use App\Irc\Domain\Event\UserQuitNetworkEvent;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

/**
 * Listens only to domain events and maintains in-memory network state (repos).
 * Protocol-specific parsing is done by adapters (Unreal / InspIRCd) which
 * dispatch these events; this subscriber is the single place that writes to
 * ChannelRepository and NetworkUserRepository.
 */
final readonly class NetworkStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NetworkUserRepositoryInterface $userRepository,
        private ChannelRepositoryInterface $channelRepository,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Priorities per Symfony 7.4 event_dispatcher: higher = runs earlier; range -256..256.
     *
     * @see https://symfony.com/doc/7.4/event_dispatcher.html
     */
    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedNetworkEvent::class => ['onUserJoinedNetwork', 0],
            UserQuitNetworkEvent::class => ['onUserQuitNetwork', 0],
            UserNickChangedEvent::class => ['onUserNickChanged', 10],
            UserModeChangedEvent::class => ['onUserModeChanged', 0],
            UserHostChangedEvent::class => ['onUserHostChanged', 0],
            ChannelSyncedEvent::class => ['onChannelSynced', 0],
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
            UserLeftChannelEvent::class => ['onUserLeftChannel', 0],
            ChannelModesChangedEvent::class => ['onChannelModesChanged', 0],
            ChannelTopicChangedEvent::class => ['onChannelTopicChanged', 0],
        ];
    }

    public function onUserJoinedNetwork(UserJoinedNetworkEvent $event): void
    {
        $this->userRepository->add($event->user);
    }

    public function onUserQuitNetwork(UserQuitNetworkEvent $event): void
    {
        $user = $this->userRepository->findByUid($event->uid);
        if (null === $user) {
            return;
        }

        foreach ($user->getChannelNames() as $channelNameStr) {
            $channel = $this->channelRepository->findByName(new ChannelName($channelNameStr));
            if (null !== $channel) {
                $channel->removeMember($event->uid);
                $this->channelRepository->save($channel);
            }
        }

        $this->userRepository->removeByUid($event->uid);
        $this->logger->info(sprintf('User quit: %s [%s] — %s', $event->nick->value, $event->uid->value, $event->reason));
    }

    public function onUserNickChanged(UserNickChangedEvent $event): void
    {
        $this->userRepository->updateNick($event->uid, $event->oldNick, $event->newNick);
    }

    public function onUserModeChanged(UserModeChangedEvent $event): void
    {
        $user = $this->userRepository->findByUid($event->uid);
        if (null !== $user) {
            $user->applyModeChange($event->modeDelta);
        }
    }

    public function onUserHostChanged(UserHostChangedEvent $event): void
    {
        $user = $this->userRepository->findByUid($event->uid);
        if (null !== $user) {
            $user->updateVirtualHost($event->newHost);
        }
    }

    public function onChannelSynced(ChannelSyncedEvent $event): void
    {
        $channel = $event->channel;
        $this->channelRepository->save($channel);

        foreach ($channel->getMembers() as $member) {
            $joinedUser = $this->userRepository->findByUid($member->uid);
            if (null !== $joinedUser) {
                $joinedUser->addChannel($channel->name);
            }
        }
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $user = $this->userRepository->findByUid($event->uid);
        if (null !== $user) {
            $user->addChannel($event->channel);
        }
    }

    public function onUserLeftChannel(UserLeftChannelEvent $event): void
    {
        $user = $this->userRepository->findByUid($event->uid);
        if (null !== $user) {
            $user->removeChannel($event->channel);
        }

        $channel = $this->channelRepository->findByName($event->channel);
        if (null !== $channel) {
            $channel->removeMember($event->uid);
            $this->channelRepository->save($channel);
        }
    }

    public function onChannelModesChanged(ChannelModesChangedEvent $event): void
    {
        $this->channelRepository->save($event->channel);
    }

    public function onChannelTopicChanged(ChannelTopicChangedEvent $event): void
    {
        $this->channelRepository->save($event->channel);
    }
}
