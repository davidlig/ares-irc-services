<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelLevels\CleanupChannelLevels;
use App\ChanServ\Application\UseCase\CleanupChannelLevels\CleanupChannelLevelsHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServLevelsChannelDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CleanupChannelLevelsHandler $cleanupChannelLevels,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropCleanupEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropCleanupEvent $event): void
    {
        $this->cleanupChannelLevels->handle(new CleanupChannelLevels($event->channelId));
    }
}
