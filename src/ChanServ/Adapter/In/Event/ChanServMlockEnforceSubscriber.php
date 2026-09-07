<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlock;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementTrigger;
use App\ChanServ\Application\UseCase\EnforceMlock\SynchronizeAllChannelMlocksHandler;
use App\Irc\Application\PublishedEvent\ChannelSettingsChangedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Translates IRC events to the ChanServ MLOCK enforcement use case. */
final readonly class ChanServMlockEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EnforceChannelMlockHandlerInterface $enforceChannelMlock,
        private SynchronizeAllChannelMlocksHandler $synchronizeAllChannelMlocks,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSynchronizationCompletedEvent::class => ['onNetworkSynchronizationCompleted', -10],
            ChannelSynchronizedEvent::class => ['onChannelSynced', -10],
            ChannelSettingsChangedEvent::class => ['onChannelModesChanged', 255],
            ChannelMlockUpdatedEvent::class => ['onMlockUpdated', 0],
        ];
    }

    public function onNetworkSynchronizationCompleted(): void
    {
        $this->synchronizeAllChannelMlocks->handle();
    }

    public function onChannelSynced(ChannelSynchronizedEvent $event): void
    {
        $this->enforceChannelMlock->handle(new EnforceChannelMlock(
            $event->channelName,
            MlockEnforcementTrigger::ChannelSynchronized,
        ));
    }

    public function onChannelModesChanged(ChannelSettingsChangedEvent $event): void
    {
        $this->enforceChannelMlock->handle(new EnforceChannelMlock(
            $event->channelName,
            MlockEnforcementTrigger::ModesChanged,
        ));
    }

    public function onMlockUpdated(ChannelMlockUpdatedEvent $event): void
    {
        $this->enforceChannelMlock->handle(new EnforceChannelMlock(
            $event->channelName,
            MlockEnforcementTrigger::LockUpdated,
        ));
    }
}
