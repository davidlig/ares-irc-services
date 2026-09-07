<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\Domain\ChanServ\Event\ChannelDropCleanupEvent;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a channel is dropped, remove all MemoServ data for that channel (memos, ignores, settings).
 */
final readonly class MemoServChannelDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
        private MemoIgnoreRepositoryInterface $memoIgnoreRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelDropCleanupEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelDrop(ChannelDropCleanupEvent $event): void
    {
        $this->memoRepository->deleteAllForChannel($event->channelId);
        $this->memoIgnoreRepository->deleteAllForChannel($event->channelId);
        $this->memoSettingsRepository->deleteAllForChannel($event->channelId);
    }
}
