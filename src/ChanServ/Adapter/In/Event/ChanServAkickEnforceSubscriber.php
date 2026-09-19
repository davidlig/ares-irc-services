<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkick;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicks;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicksHandlerInterface;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Translates join and end-of-synchronization events to AKICK use cases.
 */
final readonly class ChanServAkickEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EnforceChannelAkickHandlerInterface $enforceChannelAkick,
        private SynchronizeAllChannelAkicksHandlerInterface $synchronizeAllChannelAkicks,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoined', 0],
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 0],
        ];
    }

    public function onUserJoined(UserJoinedChannelEvent $event): void
    {
        $this->enforceChannelAkick->handle(new EnforceChannelAkick(
            $event->channelName,
            $event->uid,
            new DateTimeImmutable(),
        ));
    }

    public function onSyncComplete(NetworkSynchronizationCompletedEvent $event): void
    {
        $this->synchronizeAllChannelAkicks->handle(new SynchronizeAllChannelAkicks(new DateTimeImmutable()));
    }
}
