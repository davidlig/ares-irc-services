<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a nick is dropped:
 * - Remove the IRCOP entry for that nick (CASCADE DELETE)
 * - Clear creator reference in GLINE entries (SET NULL).
 */
final readonly class OperServNickDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OperIrcopRepositoryInterface $operIrcopRepository,
        private GlineRepositoryInterface $glineRepository,
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
        $this->operIrcopRepository->deleteByNickId($event->nickId);

        $this->glineRepository->clearCreatorNickId($event->nickId);
    }
}
