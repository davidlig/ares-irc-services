<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a nick is dropped, remove the IRCOP entry for that nick.
 */
final readonly class OperServNickDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OperIrcopRepositoryInterface $operIrcopRepository,
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
    }
}
