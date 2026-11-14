<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Flushes deferred channel activity on disconnection. The buffer logs and retains failed chunks
 * itself, so a database failure never breaks shutdown.
 */
final readonly class ChanServChannelActivityFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelActivityBuffer $activityBuffer,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [NetworkConnectionLostEvent::class => 'onConnectionLost'];
    }

    public function onConnectionLost(NetworkConnectionLostEvent $event): void
    {
        $this->activityBuffer->flush();
    }
}
