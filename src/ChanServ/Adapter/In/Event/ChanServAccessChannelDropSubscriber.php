<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelAccess\CleanupChannelAccess;
use App\ChanServ\Application\UseCase\CleanupChannelAccess\CleanupChannelAccessHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServAccessChannelDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CleanupChannelAccessHandler $cleanupChannelAccess,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropCleanupEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropCleanupEvent $event): void
    {
        $this->cleanupChannelAccess->handle(new CleanupChannelAccess($event->channelId));
    }
}
