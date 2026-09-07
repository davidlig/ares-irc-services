<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\MotdRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
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
        private MotdRepositoryInterface $motdRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropCleanupEvent::class => ['onNickDrop', 0],
        ];
    }

    public function onNickDrop(NickDropCleanupEvent $event): void
    {
        $this->operIrcopRepository->deleteByNickId($event->nickId);

        $this->glineRepository->clearCreatorNickId($event->nickId);

        $this->motdRepository->deleteByNickId($event->nickId);
    }
}
