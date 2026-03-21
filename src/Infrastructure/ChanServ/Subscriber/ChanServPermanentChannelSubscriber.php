<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Applies +P (permanent channel mode) when channels are registered,
 * and removes -P when channels are dropped or expire.
 *
 * The permanent mode prevents the channel from being destroyed when
 * the last user leaves. This is supported by UnrealIRCd (+P) and InspIRCd (+P).
 */
final readonly class ChanServPermanentChannelSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ActiveChannelModeSupportProviderInterface $modeSupportProvider,
        private ChannelLookupPort $channelLookup,
        private ChannelServiceActionsPort $channelServiceActions,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelRegisteredEvent::class => ['onChannelRegistered', 0],
            ChannelDropEvent::class => ['onChannelDrop', 0],
        ];
    }

    public function onChannelRegistered(ChannelRegisteredEvent $event): void
    {
        $permanentLetter = $this->modeSupportProvider->getSupport()->getPermanentChannelModeLetter();
        if (null === $permanentLetter) {
            return;
        }

        $view = $this->channelLookup->findByChannelName($event->channelName);
        if (null === $view) {
            return;
        }

        $this->channelServiceActions->setChannelModes($event->channelName, '+' . $permanentLetter, []);
        $this->logger->debug('ChanServ set +' . $permanentLetter . ' (permanent) on channel registration', [
            'channel' => $event->channelName,
        ]);
    }

    public function onChannelDrop(ChannelDropEvent $event): void
    {
        $permanentLetter = $this->modeSupportProvider->getSupport()->getPermanentChannelModeLetter();
        if (null === $permanentLetter) {
            return;
        }

        $view = $this->channelLookup->findByChannelName($event->channelName);
        if (null === $view) {
            return;
        }

        if (!str_contains($view->modes, $permanentLetter)) {
            return;
        }

        $this->channelServiceActions->setChannelModes($event->channelName, '-' . $permanentLetter, []);
        $this->logger->debug('ChanServ removed -' . $permanentLetter . ' (permanent) on channel drop', [
            'channel' => $event->channelName,
            'reason' => $event->reason,
        ]);
    }
}
