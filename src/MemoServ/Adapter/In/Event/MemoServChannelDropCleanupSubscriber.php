<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\Application\ChanServ\PublishedEvent\ChannelDropCleanupEvent;
use App\MemoServ\Application\UseCase\CleanupChannel\CleanupChannelMemoData;
use App\MemoServ\Application\UseCase\CleanupChannel\CleanupChannelMemoDataHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a channel is dropped, remove all MemoServ data for that channel (memos, ignores, settings).
 */
final readonly class MemoServChannelDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CleanupChannelMemoDataHandler $cleanupChannelMemoData,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropCleanupEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropCleanupEvent $event): void
    {
        $this->cleanupChannelMemoData->handle(new CleanupChannelMemoData($event->channelId));
    }
}
