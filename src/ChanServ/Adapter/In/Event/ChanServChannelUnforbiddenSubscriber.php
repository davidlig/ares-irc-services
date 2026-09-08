<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\ChanServ\Application\PublishedEvent\ChannelUnforbiddenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServChannelUnforbiddenSubscriber implements EventSubscriberInterface
{
    public function __construct(private ForbiddenChannelEnforcement $enforcement) {}

    public static function getSubscribedEvents(): array
    {
        return [ChannelUnforbiddenEvent::class => ['onChannelUnforbidden', 0]];
    }

    public function onChannelUnforbidden(ChannelUnforbiddenEvent $event): void
    {
        $this->enforcement->releaseUnforbiddenChannel($event->channelName);
    }
}
