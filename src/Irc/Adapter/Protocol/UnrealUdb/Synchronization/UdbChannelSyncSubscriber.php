<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\PublishedEvent\ChannelForbiddenEvent;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelMlockUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelPendingDeletionEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRestoredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSuspendedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelTopiclockUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnforbiddenEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Domain\Event\ChannelTopicChangedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;
use function strtolower;

/**
 * Projects ChanServ SQL changes into the authoritative UDB C block.
 *
 * The UDB store is always updated (even while the link is down or another
 * protocol driver is active); only wire propagation is gated by the session
 * coordinator. Channel flags are expressed with the current UDB schema:
 * founder/topic/modes plus the numeric C::<channel>::options bitmask
 * (2 = LOCK_MODES / MLOCK, 4 = LOCK_TOPIC / TOPICLOCK).
 */
final class UdbChannelSyncSubscriber implements EventSubscriberInterface
{
    private const string BLOCK = 'C';

    public function __construct(
        private UdbRecordWriterInterface $recordWriter,
        private ChannelProjectionQuery $channels,
        private UdbRecordExporter $exporter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelRegisteredEvent::class => 'onChannelRegistered',
            ChannelDropEvent::class => 'onChannelDrop',
            ChannelPendingDeletionEvent::class => 'onChannelPendingDeletion',
            ChannelRestoredEvent::class => 'onChannelRestored',
            ChannelFounderChangedEvent::class => 'onChannelFounderChanged',
            ChannelForbiddenEvent::class => 'onChannelForbidden',
            ChannelUnforbiddenEvent::class => 'onChannelUnforbidden',
            ChannelSuspendedEvent::class => 'onChannelSuspended',
            ChannelUnsuspendedEvent::class => 'onChannelUnsuspended',
            ChannelMlockUpdatedEvent::class => 'onChannelMlockUpdated',
            ChannelTopiclockUpdatedEvent::class => 'onChannelTopiclockUpdated',
            ChannelTopicChangedEvent::class => 'onChannelTopicChanged',
            NetworkSyncCompleteEvent::class => ['onNetworkSyncComplete', -5],
        ];
    }

    public function onChannelRegistered(ChannelRegisteredEvent $event): void
    {
        $channel = $this->channels->findByName($event->channelNameLower);
        if (null === $channel) {
            return;
        }

        foreach ($this->exporter->channelRecords($channel) as $path => $value) {
            $this->recordWriter->insert(self::BLOCK, $path, $value);
        }
    }

    public function onChannelDrop(ChannelDropEvent $event): void
    {
        $this->recordWriter->delete(self::BLOCK, $event->channelName);
    }

    public function onChannelPendingDeletion(ChannelPendingDeletionEvent $event): void
    {
        $channel = $this->channels->findByName($event->channelNameLower);
        if (null === $channel) {
            return;
        }

        $this->writeSuspend($channel);
    }

    public function onChannelRestored(ChannelRestoredEvent $event): void
    {
        $this->recordWriter->delete(self::BLOCK, sprintf('%s::suspend', $event->channelName));
    }

    public function onChannelFounderChanged(ChannelFounderChangedEvent $event): void
    {
        $channel = $this->channels->findByName(strtolower($event->channelName));
        if (null === $channel) {
            return;
        }

        $records = $this->exporter->channelRecords($channel);
        $path = sprintf('%s::founder', $channel->name);
        if (isset($records[$path])) {
            $this->recordWriter->insert(self::BLOCK, $path, $records[$path]);
        }
    }

    public function onChannelForbidden(ChannelForbiddenEvent $event): void
    {
        $this->recordWriter->insert(self::BLOCK, sprintf('%s::forbid', $event->channelName), $event->reason);
    }

    public function onChannelUnforbidden(ChannelUnforbiddenEvent $event): void
    {
        $this->recordWriter->delete(self::BLOCK, sprintf('%s::forbid', $event->channelName));
    }

    public function onChannelSuspended(ChannelSuspendedEvent $event): void
    {
        $reason = '' !== $event->reason ? $event->reason : '1';
        $this->recordWriter->insert(self::BLOCK, sprintf('%s::suspend', $event->channelName), $reason);
        $this->refreshOptions($event->channelNameLower);
    }

    public function onChannelUnsuspended(ChannelUnsuspendedEvent $event): void
    {
        $this->recordWriter->delete(self::BLOCK, sprintf('%s::suspend', $event->channelName));
        $this->refreshOptions($event->channelNameLower);
    }

    public function onChannelMlockUpdated(ChannelMlockUpdatedEvent $event): void
    {
        $this->refreshOptions(strtolower($event->channelName));
        $this->refreshMlock(strtolower($event->channelName));
    }

    public function onChannelTopiclockUpdated(ChannelTopiclockUpdatedEvent $event): void
    {
        $this->refreshOptions(strtolower($event->channelName));
    }

    private function refreshMlock(string $channelNameLower): void
    {
        $channel = $this->channels->findByName($channelNameLower);
        if (null === $channel || $channel->forbidden) {
            return;
        }

        $records = $this->exporter->channelRecords($channel);
        $path = sprintf('%s::modes', $channel->name);
        if (isset($records[$path])) {
            $this->recordWriter->insert(self::BLOCK, $path, $records[$path]);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, $path);
    }

    public function onChannelTopicChanged(ChannelTopicChangedEvent $event): void
    {
        $channelName = $event->channel->name->value;
        $channel = $this->channels->findByName(strtolower($channelName));
        if (null === $channel || $channel->forbidden) {
            return;
        }

        $topic = $event->channel->getTopic();
        if (null !== $topic && '' !== $topic) {
            $this->recordWriter->insert(self::BLOCK, sprintf('%s::topic', $channelName), $topic);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, sprintf('%s::topic', $channelName));
    }

    /**
     * Reconciles every options record after the UDB store initializer has run.
     * This removes legacy PERSISTENT/+P (*8) bits from existing stores and
     * aligns suspension markers (including channels already pending deletion).
     */
    public function onNetworkSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        foreach ($this->channels->all() as $channel) {
            $this->writeOptions($channel);
            $this->writeSuspend($channel);
        }
    }

    /** Recomputes the numeric options record after any flag change. */
    private function refreshOptions(string $channelNameLower): void
    {
        $channel = $this->channels->findByName($channelNameLower);
        if (null === $channel) {
            return;
        }

        $this->writeOptions($channel);
    }

    /** Upserts or removes the UDB-owned suspension marker for one channel. */
    private function writeSuspend(ChannelProjection $channel): void
    {
        $path = sprintf('%s::suspend', $channel->name);
        $records = $this->exporter->suspendRecords($channel);

        if (isset($records[$path])) {
            $this->recordWriter->insert(self::BLOCK, $path, $records[$path]);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, $path);
    }

    private function writeOptions(ChannelProjection $channel): void
    {
        $path = sprintf('%s::options', $channel->name);
        $options = $this->exporter->channelOptions($channel);
        if (0 !== $options) {
            $this->recordWriter->insert(self::BLOCK, $path, '*' . $options);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, $path);
    }
}
