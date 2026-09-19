<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Drops all volatile UDB session state when the S2S link is lost.
 * Persistent state (services SQL and the confirmed I/S/L mirror) is never
 * rolled back; the next link restarts HEL negotiation from scratch.
 */
final readonly class UdbConnectionLifecycleSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UdbSessionController $coordinator,
        private UdbSessionLock $lock = new UdbSessionLock(''),
        private ?UnrealUdbProtocolHandler $protocolHandler = null,
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
        $this->protocolHandler?->resetRemoteIdentity();
        $this->lock->release();
    }
}
