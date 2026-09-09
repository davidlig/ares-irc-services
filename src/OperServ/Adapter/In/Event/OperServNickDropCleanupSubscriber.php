<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Application\Port\Out\MotdRepository;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
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
        private GlineRepository $glineRepository,
        private MotdRepository $motdRepository,
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

        $this->glineRepository->clearCreatorAccountId($event->nickId);

        $this->motdRepository->deleteByCreatorAccountId($event->nickId);
    }
}
