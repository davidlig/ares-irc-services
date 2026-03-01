<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Application\Port\ChannelSyncCompletedRegistryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs last (priority -100) after all ChannelSyncedEvent subscribers (+r, SECURE, MLOCK, topic apply).
 * Marks the channel as "sync completed" so that topic/other DB persistence from the wire
 * is only allowed after our sync has run (avoids race where user with temporary op changes topic).
 */
final readonly class ChannelSyncCompletedMarkerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelSyncCompletedRegistryInterface $registry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelSyncedEvent::class => ['onChannelSynced', -100],
        ];
    }

    public function onChannelSynced(ChannelSyncedEvent $event): void
    {
        $this->registry->markSyncCompleted($event->channel->name->value);
    }
}
