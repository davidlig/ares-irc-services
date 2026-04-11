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
 * Manages +r (registered) and +P (permanent) channel modes for registered channels.
 * - +r: Set on registered channels, remove from unregistered channels
 * - +P: Set on registered channels, remove from unregistered channels
 * Both modes are applied conditionally based on IRCd support.
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
                ['onSyncCompleteReconcileRegisteredMode', 10],
                ['onSyncCompleteReconcilePermanentMode', 9],
            ],
            ChannelSyncedEvent::class => ['onChannelSyncedSetRegistered', 10],
        ];
    }

    /**
     * Set +r on a registered channel when it syncs (first user joins).
     * Skips if channel already has the registered mode.
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
        if ($registered->isSuspended()) {
            return;
        }
        $modeSupport = $this->modeSupportProvider->getSupport();
        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null === $registeredLetter) {
            return;
        }
        $view = $this->channelLookup->findByChannelName($channelName);
        if (null === $view) {
            return;
        }
        if (str_contains($view->modes, $registeredLetter)) {
            return;
        }
        $this->channelServiceActions->setChannelModes($channelName, '+' . $registeredLetter, []);
        $this->logger->debug('ChanServ set +' . $registeredLetter . ' (channel registered) on sync', ['channel' => $channelName]);
    }

    /**
     * Reconcile registered mode: registered channels get it, unregistered lose it.
     * Runs on NetworkSyncCompleteEvent (priority 10).
     */
    public function onSyncCompleteReconcileRegisteredMode(NetworkSyncCompleteEvent $event): void
    {
        $modeSupport = $this->modeSupportProvider->getSupport();
        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null === $registeredLetter) {
            return;
        }

        $registeredChannels = $this->channelRepository->listAll();
        $registeredNames = [];
        foreach ($registeredChannels as $channel) {
            $registeredNames[strtolower($channel->getName())] = true;
        }

        foreach ($registeredChannels as $channel) {
            if ($channel->isSuspended()) {
                continue;
            }
            $view = $this->channelLookup->findByChannelName($channel->getName());
            if (null === $view) {
                continue;
            }
            if (!str_contains($view->modes, $registeredLetter)) {
                $this->channelServiceActions->setChannelModes($view->name, '+' . $registeredLetter, []);
                $this->logger->debug('ChanServ set +' . $registeredLetter . ' (registered) missing on registered channel', ['channel' => $view->name]);
            }
        }

        $allViews = $this->channelLookup->listAll();
        foreach ($allViews as $view) {
            $nameLower = strtolower($view->name);
            if (!isset($registeredNames[$nameLower]) && str_contains($view->modes, $registeredLetter)) {
                $this->channelServiceActions->setChannelModes($view->name, '-' . $registeredLetter, []);
                $this->logger->debug('ChanServ removed -' . $registeredLetter . ' (registered) from unregistered channel', ['channel' => $view->name]);
            }
        }
    }

    /**
     * Reconcile permanent mode: registered channels get it, unregistered lose it.
     * Runs after onSyncCompleteReconcileRegisteredMode (priority 9 vs 10).
     */
    public function onSyncCompleteReconcilePermanentMode(NetworkSyncCompleteEvent $event): void
    {
        $modeSupport = $this->modeSupportProvider->getSupport();
        $permanentLetter = $modeSupport->getPermanentChannelModeLetter();
        if (null === $permanentLetter) {
            return;
        }

        $registeredChannels = $this->channelRepository->listAll();
        $registeredNames = [];
        foreach ($registeredChannels as $channel) {
            $registeredNames[strtolower($channel->getName())] = true;
        }

        foreach ($registeredChannels as $channel) {
            if ($channel->isSuspended()) {
                continue;
            }
            $view = $this->channelLookup->findByChannelName($channel->getName());
            if (null === $view) {
                continue;
            }
            if (!str_contains($view->modes, $permanentLetter)) {
                $this->channelServiceActions->setChannelModes($view->name, '+' . $permanentLetter, []);
                $this->logger->debug('ChanServ set +' . $permanentLetter . ' (permanent) missing on registered channel', ['channel' => $view->name]);
            }
        }

        $allViews = $this->channelLookup->listAll();
        foreach ($allViews as $view) {
            $nameLower = strtolower($view->name);
            if (!isset($registeredNames[$nameLower]) && str_contains($view->modes, $permanentLetter)) {
                $this->channelServiceActions->setChannelModes($view->name, '-' . $permanentLetter, []);
                $this->logger->debug('ChanServ removed -' . $permanentLetter . ' (permanent) from unregistered channel', ['channel' => $view->name]);
            }
        }
    }
}
