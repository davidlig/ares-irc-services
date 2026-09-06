<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Event;

use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Domain\Event\NickDropCleanupEvent;
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
