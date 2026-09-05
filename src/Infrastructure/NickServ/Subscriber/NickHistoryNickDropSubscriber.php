<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Domain\NickServ\Event\NickDropCleanupEvent;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Cleans up nickname history when a nickname is dropped.
 */
final readonly class NickHistoryNickDropSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NickHistoryRepositoryInterface $historyRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropCleanupEvent::class => ['onNickDrop', 0],
        ];
    }

    public function onNickDrop(NickDropCleanupEvent $event): void
    {
        $this->historyRepository->deleteByNickId($event->nickId);
    }
}
