<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\ChannelHistoryRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServHistoryChannelDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelHistoryRepositoryInterface $historyRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropEvent $event): void
    {
        $this->historyRepository->deleteByChannelId($event->channelId);
    }
}
