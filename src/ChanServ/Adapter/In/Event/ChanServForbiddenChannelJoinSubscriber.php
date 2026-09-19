<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServForbiddenChannelJoinSubscriber implements EventSubscriberInterface
{
    public function __construct(private ForbiddenChannelEnforcement $enforcement) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 10],
            ChannelSynchronizedEvent::class => ['onChannelSynced', 10],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $this->enforcement->enforceForbiddenUserJoin($event->channelName, (string) $event->uid);
    }

    public function onChannelSynced(ChannelSynchronizedEvent $event): void
    {
        $this->enforcement->enforceConfiguredForbiddenChannel($event->channelName);
    }
}
