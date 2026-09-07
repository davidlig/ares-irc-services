<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServAccessChannelDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelAccessRepositoryInterface $accessRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropCleanupEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropCleanupEvent $event): void
    {
        $this->accessRepository->deleteByChannelId($event->channelId);
    }
}
