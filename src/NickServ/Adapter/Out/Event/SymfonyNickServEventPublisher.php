<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Event;

use App\NickServ\Application\Port\In\NickServEvent;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\Shared\Application\Port\EventBusInterface;

final readonly class SymfonyNickServEventPublisher implements NickServEventPublisher
{
    public function __construct(private EventBusInterface $eventBus) {}

    public function publish(NickServEvent $event): void
    {
        $this->eventBus->dispatch($event);
    }
}
