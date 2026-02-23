<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC;

use App\Application\IRC\BurstCompleteRegistry;
use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps BurstCompleteRegistry in sync with the link lifecycle.
 *
 * - On connect: burst not complete until we receive EOS.
 * - On EOS/ENDBURST: burst complete, maintenance may run.
 * - On disconnect: reset so next connect waits for EOS again.
 */
final class BurstCompleteRegistrySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BurstCompleteRegistry $registry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConnectionEstablishedEvent::class => ['onConnectionEstablished', 0],
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 0],
            ConnectionLostEvent::class => ['onConnectionLost', 0],
        ];
    }

    public function onConnectionEstablished(ConnectionEstablishedEvent $event): void
    {
        $this->registry->setBurstComplete(false);
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->registry->setBurstComplete(true);
    }

    public function onConnectionLost(ConnectionLostEvent $event): void
    {
        $this->registry->setBurstComplete(false);
    }
}
