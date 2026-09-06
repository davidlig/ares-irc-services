<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Event;

use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Domain\Event\NickDropCleanupEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a nick is dropped, clear created_by_nick_id references in forbidden_vhosts table.
 */
final readonly class ForbiddenVhostCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ForbiddenVhostRepositoryInterface $forbiddenVhostRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropCleanupEvent::class => ['onNickDrop', 0],
        ];
    }

    public function onNickDrop(NickDropCleanupEvent $event): void
    {
        $this->forbiddenVhostRepository->clearCreatedByNickId($event->nickId);
    }
}
