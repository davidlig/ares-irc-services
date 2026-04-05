<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a nick is dropped, clear created_by_nick_id references in forbidden_vhosts table.
 */
final readonly class ForbiddenVhostCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ForbiddenVhostRepositoryInterface $forbiddenVhostRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropEvent::class => ['onNickDrop', 0],
        ];
    }

    public function onNickDrop(NickDropEvent $event): void
    {
        $this->forbiddenVhostRepository->clearCreatedByNickId($event->nickId);
    }
}
