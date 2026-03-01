<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

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
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelSyncedEvent::class => ['onChannelSynced', -20],
            NetworkSyncCompleteEvent::class => ['onSyncComplete', -20],
        ];
    }

    /**
     * Runs last (priority -20): apply stored topic for this channel if any.
     */
    public function onChannelSynced(ChannelSyncedEvent $event): void
    {
        $channelName = $event->channel->name->value;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered) {
            return;
        }

        $topic = $registered->getTopic();
        if (null === $topic) {
            return;
        }

        $this->channelServiceActions->setChannelTopic($channelName, $topic);
        $this->logger->debug('ChanServ applied stored topic on sync', ['channel' => $channelName]);
    }

    /**
     * Runs last (priority -20): apply stored topic for each registered channel that has a view.
     */
    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $channels = $this->channelRepository->listAll();
        foreach ($channels as $channel) {
            $topic = $channel->getTopic();
            if (null === $topic) {
                continue;
            }

            $view = $this->channelLookup->findByChannelName($channel->getName());
            if (null === $view) {
                continue;
            }

            $this->channelServiceActions->setChannelTopic($view->name, $topic);
            $this->logger->debug('ChanServ applied stored topic', ['channel' => $view->name]);
        }
    }
}
