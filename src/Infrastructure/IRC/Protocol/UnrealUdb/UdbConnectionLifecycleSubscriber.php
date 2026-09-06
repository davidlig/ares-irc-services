<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Irc\Domain\Event\ConnectionLostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Drops all volatile UDB session state when the S2S link is lost.
 * Persistent state (services SQL and the confirmed I/S/L mirror) is never
 * rolled back; the next link restarts HEL negotiation from scratch.
 */
final readonly class UdbConnectionLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UdbSessionCoordinator $coordinator,
        private UdbSessionLock $lock = new UdbSessionLock(''),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConnectionLostEvent::class => 'onConnectionLost',
        ];
    }

    public function onConnectionLost(ConnectionLostEvent $event): void
    {
        $this->coordinator->reset();
        $this->lock->release();
    }
}
