<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Event\IrcMessageProcessedEvent;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
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
            UserNickChangedEvent::class => ['publishNicknameChanged', -10],
            UserModeChangedEvent::class => ['publishModesChanged', -10],
            UserQuitNetworkEvent::class => ['publishUserLeft', -10],
            NetworkBurstCompleteEvent::class => [
                ['publishServiceIntroductionRequested', 100],
                ['publishNetworkSynchronizationCompleted', -256],
            ],
            IrcMessageProcessedEvent::class => ['publishMessageHandled', -200],
        ];
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

    public function publishServiceIntroductionRequested(NetworkBurstCompleteEvent $event): void
    {
        $this->eventDispatcher->dispatch(new ServiceIntroductionRequestedEvent($event->serverSid));
    }

    public function publishNetworkSynchronizationCompleted(NetworkBurstCompleteEvent $event): void
    {
        $this->eventDispatcher->dispatch(new NetworkSynchronizationCompletedEvent($event->serverSid));
    }

    public function publishMessageHandled(IrcMessageProcessedEvent $event): void
    {
        $this->eventDispatcher->dispatch(new IrcMessageHandledEvent());
    }
}
