<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\UseCase\DeliverEntryMessage\DeliverChannelEntryMessage;
use App\ChanServ\Application\UseCase\DeliverEntryMessage\DeliverChannelEntryMessageHandlerInterface;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Translates joins to channel entry-message delivery requests. */
final readonly class ChanServEntryMsgSubscriber implements EventSubscriberInterface
{
    public function __construct(private DeliverChannelEntryMessageHandlerInterface $handler) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $this->handler->handle(new DeliverChannelEntryMessage(
            $event->channelName,
            $event->uid,
        ));
    }
}
