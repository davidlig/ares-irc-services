<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Event;

use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\NickServ\Application\PublishedEvent\NickDropEvent;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;
use App\Shared\Application\Port\EventBusInterface;

final readonly class SymfonyNickServEventPublisher implements NickServEventPublisher
{
    public function __construct(private EventBusInterface $eventBus) {}

    public function publish(
        NickDropCleanupEvent|NickDropEvent|NickIdentifiedEvent|UserDeidentifiedEvent $event,
    ): void {
        $this->eventBus->dispatch($event);
    }
}
