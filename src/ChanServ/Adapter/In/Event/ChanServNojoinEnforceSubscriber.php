<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoin;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceNojoin\SynchronizeAllChannelNojoinHandlerInterface;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Translates join and end-of-synchronization events to NOJOIN use cases.
 */
final readonly class ChanServNojoinEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EnforceChannelNojoinHandlerInterface $enforceChannelNojoin,
        private SynchronizeAllChannelNojoinHandlerInterface $synchronizeAllChannelNojoin,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoined', 10],
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 10],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $this->onUserJoined($event);
    }

    public function onUserJoined(UserJoinedChannelEvent $event): void
    {
        $this->enforceChannelNojoin->handle(new EnforceChannelNojoin($event->channelName, $event->uid));
    }

    public function onSyncComplete(NetworkSynchronizationCompletedEvent $event): void
    {
        $this->synchronizeAllChannelNojoin->handle();
    }
}
