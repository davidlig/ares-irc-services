<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\ChanServ\Application\PublishedEvent\ChannelForbiddenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServChannelForbiddenSubscriber implements EventSubscriberInterface
{
    public function __construct(private ForbiddenChannelEnforcement $enforcement) {}

    public static function getSubscribedEvents(): array
    {
        return [ChannelForbiddenEvent::class => ['onChannelForbidden', 0]];
    }

    public function onChannelForbidden(ChannelForbiddenEvent $event): void
    {
        $this->enforcement->enforcePublishedForbiddenChannel($event->channelName);
    }
}
