<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\Out\RegisteredChannelBurstSnapshot;
use App\Irc\Application\PublishedEvent\NetworkConnectionEstablishedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServBurstChannelSnapshotSubscriber implements EventSubscriberInterface
{
    public function __construct(private RegisteredChannelBurstSnapshot $snapshot) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkConnectionEstablishedEvent::class => 'onConnect',
            NetworkConnectionLostEvent::class => 'onEnd',
            NetworkSynchronizationCompletedEvent::class => ['onEnd', -512],
        ];
    }

    public function onConnect(): void
    {
        $this->snapshot->beginBurst();
    }

    public function onEnd(): void
    {
        $this->snapshot->endBurst();
    }
}
