<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServAccessChannelDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelAccessRepositoryInterface $accessRepository,
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
        $this->accessRepository->deleteByChannelId($event->channelId);
    }
}
