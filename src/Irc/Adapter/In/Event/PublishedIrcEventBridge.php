<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Event\IrcMessageProcessedEvent;
use App\Irc\Adapter\Event\MessageReceivedEvent;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Network\Event\ChannelTopicReceivedEvent;
use App\Irc\Application\PublishedEvent\ChannelSettingsChangedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\ChannelTopicReceivedEvent as PublishedChannelTopicReceivedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandlingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\Irc\Application\PublishedEvent\UserDepartedChannelEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent as PublishedUserJoinedChannelEvent;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\Irc\Domain\Event\ChannelModesChangedEvent;
use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\Irc\Domain\Event\UserLeftChannelEvent;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\Event\UserNickChangedEvent;
use App\Irc\Domain\Event\UserQuitNetworkEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Publishes stable scalar events after IRC adapter state has been updated. */
final readonly class PublishedIrcEventBridge implements EventSubscriberInterface
{
    public function __construct(private EventDispatcherInterface $eventDispatcher) {}

    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['publishMessageHandlingStarted', 256],
            UserNickChangedEvent::class => ['publishNicknameChanged', -10],
            UserModeChangedEvent::class => ['publishModesChanged', -10],
            UserQuitNetworkEvent::class => ['publishUserLeft', -10],
            UserJoinedChannelEvent::class => ['publishUserJoinedChannel', -10],
            UserLeftChannelEvent::class => ['publishUserDepartedChannel', 10],
            ChannelSyncedEvent::class => ['publishChannelSynchronized', -4],
            ChannelTopicReceivedEvent::class => ['publishChannelTopicReceived', 0],
            ChannelModesChangedEvent::class => ['publishChannelSettingsChanged', -1],
            NetworkBurstCompleteEvent::class => [
                ['publishServiceIntroductionRequested', 100],
            ],
            NetworkSyncCompleteEvent::class => ['publishNetworkSynchronizationCompleted', -256],
            IrcMessageProcessedEvent::class => ['publishMessageHandled', -200],
        ];
    }

    public function publishMessageHandlingStarted(): void
    {
        $this->eventDispatcher->dispatch(new IrcMessageHandlingStartedEvent());
    }

    public function publishNicknameChanged(UserNickChangedEvent $event): void
    {
        $this->eventDispatcher->dispatch(new UserNicknameChangedEvent(
            $event->uid->value,
            $event->oldNick->value,
            $event->newNick->value,
        ));
    }

    public function publishModesChanged(UserModeChangedEvent $event): void
    {
        $this->eventDispatcher->dispatch(new UserModesChangedEvent($event->uid->value, $event->modeDelta));
    }

    public function publishUserLeft(UserQuitNetworkEvent $event): void
    {
        $this->eventDispatcher->dispatch(new UserLeftNetworkEvent(
            $event->uid->value,
            $event->nick->value,
            $event->reason,
            $event->ident,
            $event->displayHost,
            $event->hostname,
            $event->ipBase64,
        ));
    }

    public function publishUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $this->eventDispatcher->dispatch(new PublishedUserJoinedChannelEvent(
            $event->uid->value,
            $event->channel->value,
            '' === $event->role->value ? null : $event->role->value,
        ));
    }

    public function publishUserDepartedChannel(UserLeftChannelEvent $event): void
    {
        $this->eventDispatcher->dispatch(new UserDepartedChannelEvent(
            $event->uid->value,
            $event->channel->value,
        ));
    }

    public function publishChannelSynchronized(ChannelSyncedEvent $event): void
    {
        $this->eventDispatcher->dispatch(new ChannelSynchronizedEvent(
            $event->channel->name->value,
            $event->channelSetupApplicable,
        ));
    }

    public function publishChannelTopicReceived(ChannelTopicReceivedEvent $event): void
    {
        $this->eventDispatcher->dispatch(new PublishedChannelTopicReceivedEvent(
            $event->channelName->value,
            $event->topic,
            $event->setterNick,
            $event->sourceUid,
        ));
    }

    public function publishChannelSettingsChanged(ChannelModesChangedEvent $event): void
    {
        $this->eventDispatcher->dispatch(new ChannelSettingsChangedEvent($event->channel->name->value));
    }

    public function publishServiceIntroductionRequested(NetworkBurstCompleteEvent $event): void
    {
        $this->eventDispatcher->dispatch(new ServiceIntroductionRequestedEvent($event->serverSid));
    }

    public function publishNetworkSynchronizationCompleted(NetworkSyncCompleteEvent $event): void
    {
        $this->eventDispatcher->dispatch(new NetworkSynchronizationCompletedEvent($event->serverSid));
    }

    public function publishMessageHandled(IrcMessageProcessedEvent $event): void
    {
        $this->eventDispatcher->dispatch(new IrcMessageHandledEvent());
    }
}
