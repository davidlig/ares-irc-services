<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Event;

use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\Shared\Application\Port\EventBusInterface;

final readonly class SymfonyRegistrationEventPublisher implements RegistrationEventPublisher
{
    public function __construct(private EventBusInterface $eventBus) {}

    public function publish(NickPasswordHashAvailable $event): void
    {
        $this->eventBus->dispatch($event);
    }
}
