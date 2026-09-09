<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\UseCase\SynchronizeTopic\SynchronizeReceivedChannelTopic;
use App\ChanServ\Application\UseCase\SynchronizeTopic\SynchronizeReceivedChannelTopicHandlerInterface;
use App\Irc\Application\PublishedEvent\ChannelTopicReceivedEvent;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Translates received topic events to the topic synchronization use case. */
final readonly class ChanServTopicSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(private SynchronizeReceivedChannelTopicHandlerInterface $handler) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelTopicReceivedEvent::class => ['onTopicReceived', 0],
        ];
    }

    public function onTopicReceived(ChannelTopicReceivedEvent $event): void
    {
        $this->handler->handle(new SynchronizeReceivedChannelTopic(
            $event->channelName,
            $event->topic,
            new DateTimeImmutable(),
            $event->setterNick,
            $event->sourceUid,
        ));
    }
}
