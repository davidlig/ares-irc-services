<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Event;

use App\Application\Port\EventBusInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;

final readonly class SymfonyChanServEventPublisher implements ChanServEventPublisher
{
    public function __construct(private EventBusInterface $eventBus) {}

    public function publish(object $event): void
    {
        $this->eventBus->dispatch($event);
    }
}
