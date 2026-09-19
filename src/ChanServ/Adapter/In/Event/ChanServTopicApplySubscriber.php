<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\UseCase\ApplyStoredTopic\ApplyStoredChannelTopic;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\ApplyStoredChannelTopicHandlerInterface;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\StoredTopicApplicationTrigger;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Translates channel synchronization events to stored-topic application requests. */
final readonly class ChanServTopicApplySubscriber implements EventSubscriberInterface
{
    public function __construct(private ApplyStoredChannelTopicHandlerInterface $handler) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelSynchronizedEvent::class => ['onChannelSynced', -20],
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', -20],
        ];
    }

    public function onChannelSynced(ChannelSynchronizedEvent $event): void
    {
        $this->handler->handle(new ApplyStoredChannelTopic(
            StoredTopicApplicationTrigger::ChannelSynchronized,
            $event->channelName,
            $event->channelSetupApplicable,
        ));
    }

    public function onSyncComplete(NetworkSynchronizationCompletedEvent $event): void
    {
        $this->handler->handle(new ApplyStoredChannelTopic(
            StoredTopicApplicationTrigger::NetworkSynchronizationCompleted,
        ));
    }
}
