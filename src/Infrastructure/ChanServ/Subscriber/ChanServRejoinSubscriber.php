<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Sets +r (channel is registered at Services) on registered channels when they exist on the network.
 * On sync complete: set +r on each registered channel that has a view (channel already exists).
 * On ChannelSyncedEvent: set +r when a registered channel is created or synced (e.g. first user joined).
 * ChanServ does not join channels; configuration is applied when the channel has at least one user.
 */
final readonly class ChanServRejoinSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLookupPort $channelLookup,
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ChannelServiceActionsPort $channelServiceActions,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSyncCompleteEvent::class => [
                ['onSyncCompleteSetChannelRegistered', 10],
            ],
            ChannelSyncedEvent::class => ['onChannelSyncedSetRegistered', 10],
        ];
    }

    /**
     * Runs first (priority 10): set +r so the channel shows as registered.
     * Only when channel setup is applicable (link or channel was empty and now has users).
     */
    public function onChannelSyncedSetRegistered(ChannelSyncedEvent $event): void
    {
        if (!$event->channelSetupApplicable) {
            return;
        }
        $channelName = $event->channel->name->value;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered) {
            return;
        }
        if (!$this->modeSupportProvider->getSupport()->hasChannelRegisteredMode()) {
            return;
        }
        $this->channelServiceActions->setChannelModes($channelName, '+r', []);
        $this->logger->debug('ChanServ set +r (channel registered) on sync', ['channel' => $channelName]);
    }

    /**
     * Runs first (priority 10): set +r (channel is registered) on each registered channel that has a view.
     */
    public function onSyncCompleteSetChannelRegistered(NetworkSyncCompleteEvent $event): void
    {
        $channels = $this->channelRepository->listAll();
        if ([] === $channels) {
            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        if (!$modeSupport->hasChannelRegisteredMode()) {
            return;
        }

        foreach ($channels as $channel) {
            $view = $this->channelLookup->findByChannelName($channel->getName());
            if (null === $view) {
                continue;
            }
            $this->channelServiceActions->setChannelModes($view->name, '+r', []);
            $this->logger->debug('ChanServ set +r (channel registered)', ['channel' => $view->name]);
        }
    }
}
