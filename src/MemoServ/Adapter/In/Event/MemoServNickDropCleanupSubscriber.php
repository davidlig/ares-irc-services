<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\MemoServ\Application\UseCase\CleanupNick\CleanupNickMemoData;
use App\MemoServ\Application\UseCase\CleanupNick\CleanupNickMemoDataHandler;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a nick is dropped, remove all MemoServ data for that nick (memos, ignores, settings).
 */
final readonly class MemoServNickDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private CleanupNickMemoDataHandler $cleanupNickMemoData,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropCleanupEvent::class => ['onNickDrop', 0],
        ];
    }

    public function onNickDrop(NickDropCleanupEvent $event): void
    {
        $this->cleanupNickMemoData->handle(new CleanupNickMemoData($event->nickId));
    }
}
