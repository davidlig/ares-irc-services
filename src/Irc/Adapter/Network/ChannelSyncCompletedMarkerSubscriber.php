<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Application\Port\In\ChannelSyncCompletedRegistryInterface;
use App\Irc\Domain\Event\ChannelSyncedEvent;
use App\Irc\Domain\Event\ExistingChannelJoinObservedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Runs last (priority -100) after all ChannelSyncedEvent subscribers (+r, SECURE, MLOCK, topic apply).
 * Marks the channel as "sync completed" so that topic/other DB persistence from the wire
 * is only allowed after our sync has run (avoids race where user with temporary op changes topic).
 * Always marks on every ChannelSyncedEvent (not just initial setup) to ensure
 * sync completion is tracked for all channel sync events.
 * Also re-arms on ExistingChannelJoinObservedEvent (an SJOIN/FJOIN/IJOIN for an existing
 * channel), but only when the join comes from a server that is still bursting (initial link
 * or a server reintroduced after a netsplit), so the topic persistence grace window starts
 * again and the rejoining side's TOPIC cannot overwrite the stored topic. Ordinary joins
 * after that server's end-of-burst do not re-arm, so topics set by users stay persisted.
 */
final readonly class ChannelSyncCompletedMarkerSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelSyncCompletedRegistryInterface $registry,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelSyncedEvent::class => ['onChannelSynced', -100],
            ExistingChannelJoinObservedEvent::class => ['onExistingChannelJoinObserved', -100],
        ];
    }

    public function onChannelSynced(ChannelSyncedEvent $event): void
    {
        $this->registry->markSyncCompleted($event->channel->name->value);
    }

    public function onExistingChannelJoinObserved(ExistingChannelJoinObservedEvent $event): void
    {
        if (!$event->fromBurstingServer) {
            return;
        }

        $this->registry->markSyncCompleted($event->channel->name->value);
    }
}
