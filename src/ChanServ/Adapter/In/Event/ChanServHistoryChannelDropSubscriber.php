<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelHistory\CleanupChannelHistory;
use App\ChanServ\Application\UseCase\CleanupChannelHistory\CleanupChannelHistoryHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServHistoryChannelDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CleanupChannelHistoryHandler $cleanupChannelHistory,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropCleanupEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropCleanupEvent $event): void
    {
        $this->cleanupChannelHistory->handle(new CleanupChannelHistory($event->channelId));
    }
}
