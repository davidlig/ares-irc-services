<?php

declare(strict_types=1);

namespace App\Infrastructure\MemoServ\Subscriber;

use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Domain\NickServ\Event\NickDropCleanupEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a nick is dropped, remove all MemoServ data for that nick (memos, ignores, settings).
 */
final readonly class MemoServNickDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemoRepositoryInterface $memoRepository,
        private MemoIgnoreRepositoryInterface $memoIgnoreRepository,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropCleanupEvent::class => ['onNickDrop', 0],
        ];
    }

    public function onNickDrop(NickDropCleanupEvent $event): void
    {
        $this->memoRepository->deleteAllForNick($event->nickId);
        $this->memoIgnoreRepository->deleteAllForNick($event->nickId);
        $this->memoSettingsRepository->deleteAllForNick($event->nickId);
    }
}
