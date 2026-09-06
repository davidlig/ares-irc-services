<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Application\ChanServ\Event\ChannelTopiclockUpdatedEvent;
use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelAccessChangedEvent;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Event\ChannelForbiddenEvent;
use App\Domain\ChanServ\Event\ChannelFounderChangedEvent;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Domain\ChanServ\Event\ChannelSuspendedEvent;
use App\Domain\ChanServ\Event\ChannelUnforbiddenEvent;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
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
 * (2 = LOCK_MODES / MLOCK, 4 = LOCK_TOPIC / TOPICLOCK, 8 = PERSISTENT).
 */
final class UdbChannelSyncSubscriber implements EventSubscriberInterface
{
    private const string BLOCK = 'C';

    public function __construct(
        private UdbRecordWriterInterface $recordWriter,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private UdbRecordExporter $exporter,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelRegisteredEvent::class => 'onChannelRegistered',
            ChannelDropEvent::class => 'onChannelDrop',
            ChannelFounderChangedEvent::class => 'onChannelFounderChanged',
            ChannelForbiddenEvent::class => 'onChannelForbidden',
            ChannelUnforbiddenEvent::class => 'onChannelUnforbidden',
            ChannelSuspendedEvent::class => 'onChannelSuspended',
            ChannelUnsuspendedEvent::class => 'onChannelUnsuspended',
            ChannelAccessChangedEvent::class => 'onChannelAccessChanged',
            ChannelMlockUpdatedEvent::class => 'onChannelMlockUpdated',
            ChannelTopiclockUpdatedEvent::class => 'onChannelTopiclockUpdated',
            ChannelTopicChangedEvent::class => 'onChannelTopicChanged',
        ];
    }

    public function onChannelRegistered(ChannelRegisteredEvent $event): void
    {
        $channel = $this->channelRepository->findByChannelName($event->channelNameLower);
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

    public function onChannelFounderChanged(ChannelFounderChangedEvent $event): void
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($event->channelName));
        if (null === $channel) {
            return;
        }

        $records = $this->exporter->channelRecords($channel);
        $path = sprintf('%s::founder', $channel->getName());
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
        $this->recordWriter->insert(self::BLOCK, sprintf('%s::suspended', $event->channelName), '1');
        $this->refreshOptions($event->channelNameLower);
    }

    public function onChannelUnsuspended(ChannelUnsuspendedEvent $event): void
    {
        $this->recordWriter->delete(self::BLOCK, sprintf('%s::suspended', $event->channelName));
        $this->refreshOptions($event->channelNameLower);
    }

    public function onChannelAccessChanged(ChannelAccessChangedEvent $event): void
    {
        $path = sprintf('%s::access::%s', $event->channelName, $event->targetNickname);
        if ('DEL' === $event->action) {
            $this->recordWriter->delete(self::BLOCK, $path);

            return;
        }

        $this->recordWriter->insert(self::BLOCK, $path, (string) ($event->level ?? 0));
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
        $channel = $this->channelRepository->findByChannelName($channelNameLower);
        if (null === $channel || $channel->isForbidden()) {
            return;
        }

        $records = $this->exporter->channelRecords($channel);
        $path = sprintf('%s::modes', $channel->getName());
        if (isset($records[$path])) {
            $this->recordWriter->insert(self::BLOCK, $path, $records[$path]);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, $path);
    }

    public function onChannelTopicChanged(ChannelTopicChangedEvent $event): void
    {
        $channelName = $event->channel->name->value;
        $channel = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $channel || $channel->isForbidden()) {
            return;
        }

        $topic = $event->channel->getTopic();
        if (null !== $topic && '' !== $topic) {
            $this->recordWriter->insert(self::BLOCK, sprintf('%s::topic', $channelName), $topic);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, sprintf('%s::topic', $channelName));
    }

    /** Recomputes the numeric options record after any flag change. */
    private function refreshOptions(string $channelNameLower): void
    {
        $channel = $this->channelRepository->findByChannelName($channelNameLower);
        if (null === $channel) {
            return;
        }

        $this->writeOptions($channel);
    }

    private function writeOptions(RegisteredChannel $channel): void
    {
        $path = sprintf('%s::options', $channel->getName());
        $options = $this->exporter->channelOptions($channel);
        if (0 !== $options) {
            $this->recordWriter->insert(self::BLOCK, $path, '*' . $options);

            return;
        }

        $this->recordWriter->delete(self::BLOCK, $path);
    }
}
