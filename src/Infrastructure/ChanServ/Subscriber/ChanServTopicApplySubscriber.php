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
     * Runs last (priority -20): apply stored topic only when channel setup is applicable
     * (link or channel was empty and now has users) and topic is missing or different.
     */
    public function onChannelSynced(ChannelSyncedEvent $event): void
    {
        if (!$event->channelSetupApplicable) {
            return;
        }
        $channelName = $event->channel->name->value;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered) {
            return;
        }

        $storedTopic = $registered->getTopic();
        if (null === $storedTopic) {
            return;
        }

        $currentTopic = $event->channel->getTopic();
        if ($storedTopic === $currentTopic) {
            return;
        }

        $this->channelServiceActions->setChannelTopic($channelName, $storedTopic);
        $this->logger->debug('ChanServ applied stored topic on sync', ['channel' => $channelName]);
    }

    /**
     * Runs last (priority -20): apply stored topic for each registered channel only when missing or different.
     */
    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $channels = $this->channelRepository->listAll();
        foreach ($channels as $channel) {
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
