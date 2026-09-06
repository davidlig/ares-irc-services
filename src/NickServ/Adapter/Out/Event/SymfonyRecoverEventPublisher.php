<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Event;

use App\Application\Port\EventBusInterface;
use App\NickServ\Application\Port\Out\RecoverEventPublisher;

final readonly class SymfonyRecoverEventPublisher implements RecoverEventPublisher
{
    public function __construct(private EventBusInterface $eventBus) {}

    public function publish(object $event): void
    {
        $this->eventBus->dispatch($event);
    }
}
