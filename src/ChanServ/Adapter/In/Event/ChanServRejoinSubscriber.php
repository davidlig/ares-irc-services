<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\Port\In\ChannelRegistrationLifecycle;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ChanServRejoinSubscriber implements EventSubscriberInterface
{
    public function __construct(private ChannelRegistrationLifecycle $registrationLifecycle) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSynchronizationCompletedEvent::class => [
                ['onSyncCompleteReconcileRegisteredMode', 10],
                ['onSyncCompleteReconcilePermanentMode', 9],
            ],
            ChannelSynchronizedEvent::class => ['onChannelSyncedSetRegistered', 10],
        ];
    }

    public function onChannelSyncedSetRegistered(ChannelSynchronizedEvent $event): void
    {
        $this->registrationLifecycle->channelSynchronized($event->channelName);
    }

    public function onSyncCompleteReconcileRegisteredMode(NetworkSynchronizationCompletedEvent $event): void
    {
        $this->registrationLifecycle->reconcileRegisteredMode();
    }

    public function onSyncCompleteReconcilePermanentMode(NetworkSynchronizationCompletedEvent $event): void
    {
        $this->registrationLifecycle->reconcilePermanentMode();
    }
}
