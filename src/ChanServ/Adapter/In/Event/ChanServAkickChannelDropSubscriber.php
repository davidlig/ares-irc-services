<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelAkick\CleanupChannelAkick;
use App\ChanServ\Application\UseCase\CleanupChannelAkick\CleanupChannelAkickHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServAkickChannelDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CleanupChannelAkickHandler $cleanupChannelAkick,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropCleanupEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropCleanupEvent $event): void
    {
        $this->cleanupChannelAkick->handle(new CleanupChannelAkick($event->channelId));
    }
}
