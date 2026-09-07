<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\Application\Port\ChannelServiceActionsPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function strtolower;

/**
 * Applies the last stored topic from DB when a registered channel is synced or when sync completes.
 * Runs last (priority -20) after +r, SECURE strip and MLOCK.
 */
final readonly class ChanServTopicApplySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLookupPort $channelLookup,
        private ChannelServiceActionsPort $channelServiceActions,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelSynchronizedEvent::class => ['onChannelSynced', -20],
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', -20],
        ];
    }

    /**
     * Runs last (priority -20): apply stored topic when the channel is registered.
     * Always runs (not just on initial setup) because TOPICLOCK must re-apply
     * the stored topic whenever a registered channel is synced (e.g. after netsplit
     * or user rejoin).
     *
     * When channelSetupApplicable is true (channel is new or was empty), the stored
     * topic is always applied without comparison — the IRCd channel has no topic
     * but the in-memory Channel object may retain a stale topic from before the
     * channel became empty, causing a false match that skips topic application.
     */
    public function onChannelSynced(ChannelSynchronizedEvent $event): void
    {
        $channelName = $event->channelName;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered || $registered->isBlocked()) {
            return;
        }

        $storedTopic = $registered->getTopic();
        if (null === $storedTopic) {
            return;
        }

        $this->applyTopic($channelName, $storedTopic, $event);
    }

    private function applyTopic(string $channelName, ?string $storedTopic, ChannelSynchronizedEvent $event): void
    {
        if ($event->channelSetupApplicable) {
            $this->channelServiceActions->setChannelTopic($channelName, $storedTopic);
            $this->logger->debug('ChanServ applied stored topic on setup', ['channel' => $channelName]);

            return;
        }

        $view = $this->channelLookup->findByChannelName($channelName);
        if (null !== $view && $storedTopic === $view->topic) {
            return;
        }

        $this->channelServiceActions->setChannelTopic($channelName, $storedTopic);
        $this->logger->debug('ChanServ applied stored topic on sync', ['channel' => $channelName]);
    }

    /**
     * Runs last (priority -20): apply stored topic for each registered channel only when missing or different.
     */
    public function onSyncComplete(NetworkSynchronizationCompletedEvent $event): void
    {
        $channels = $this->channelRepository->listAll();
        foreach ($channels as $channel) {
            if ($channel->isBlocked()) {
                continue;
            }

            $storedTopic = $channel->getTopic();
            if (null === $storedTopic) {
                continue;
            }

            $view = $this->channelLookup->findByChannelName($channel->getName());
            if (null === $view) {
                continue;
            }

            if ($view->topic === $storedTopic) {
                continue;
            }

            $this->channelServiceActions->setChannelTopic($view->name, $storedTopic);
            $this->logger->debug('ChanServ applied stored topic', ['channel' => $view->name]);
        }
    }
}
