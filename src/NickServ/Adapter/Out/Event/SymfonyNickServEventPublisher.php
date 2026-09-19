<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Event;

use App\NickServ\Application\Event\NickEmailChangedEvent;
use App\NickServ\Application\Event\NickPasswordChangedEvent;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\NickServ\Application\PublishedEvent\NickDropEvent;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\PublishedEvent\NickSuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickVhostChangedEvent;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\Shared\Application\Port\EventBusInterface;

final readonly class SymfonyNickServEventPublisher implements NickServEventPublisher
{
    public function __construct(private EventBusInterface $eventBus) {}

    public function publish(
        NickDropCleanupEvent|NickDropEvent|NickEmailChangedEvent|NickIdentifiedEvent|NickPasswordChangedEvent|NickPasswordHashAvailable|NickSuspendedEvent|NickUnsuspendedEvent|NickVhostChangedEvent|UserDeidentifiedEvent $event,
    ): void {
        $this->eventBus->dispatch($event);
    }
}
