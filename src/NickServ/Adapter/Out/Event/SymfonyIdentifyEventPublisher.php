<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Event;

use App\NickServ\Application\Port\Out\IdentifyEventPublisher;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\Shared\Application\Port\EventBusInterface;

final readonly class SymfonyIdentifyEventPublisher implements IdentifyEventPublisher
{
    public function __construct(private EventBusInterface $eventBus) {}

    public function publish(NickIdentifiedEvent|NickPasswordHashAvailable $event): void
    {
        $this->eventBus->dispatch($event);
    }
}
