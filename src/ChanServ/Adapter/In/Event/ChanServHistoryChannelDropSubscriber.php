<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServHistoryChannelDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelHistoryRepositoryInterface $historyRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropCleanupEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropCleanupEvent $event): void
    {
        $this->historyRepository->deleteByChannelId($event->channelId);
    }
}
