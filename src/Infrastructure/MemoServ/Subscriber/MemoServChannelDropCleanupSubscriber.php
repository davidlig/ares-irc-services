<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Subscriber;

use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
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
        $this->memoRepository->deleteAllForChannel($event->channelId);
        $this->memoIgnoreRepository->deleteAllForChannel($event->channelId);
        $this->memoSettingsRepository->deleteAllForChannel($event->channelId);
    }
}
