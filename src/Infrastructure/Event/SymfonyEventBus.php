<?php

declare(strict_types=1);

namespace App\Infrastructure\Event;

use App\Application\Port\EventBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class SymfonyEventBus implements EventBusInterface
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function dispatch(object $event): void
    {
        $this->eventDispatcher->dispatch($event);
    }
}
