<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\In\ChannelRegistrationLifecycle;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServPermanentChannelSubscriber implements EventSubscriberInterface
{
    public function __construct(private ChannelRegistrationLifecycle $registrationLifecycle) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelRegisteredEvent::class => ['onChannelRegistered', 0],
            ChannelDropEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelRegistered(ChannelRegisteredEvent $event): void
    {
        $this->registrationLifecycle->channelRegistered($event->channelName);
    }

    public function onChannelDrop(ChannelDropEvent $event): void
    {
        $this->registrationLifecycle->channelDropped($event->channelName, $event->reason);
    }
}
