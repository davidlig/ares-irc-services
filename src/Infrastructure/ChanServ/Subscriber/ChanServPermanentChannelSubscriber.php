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
 * Applies +r (registered) and +P (permanent) channel modes when channels are registered,
 * and removes them when channels are dropped or expire.
 *
 * - +r: Shows channel as registered at services ( UnrealIRCd/InspIRCd)
 * - +P: Prevents channel destruction when last user leaves (UnrealIRCd/InspIRCd)
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
        $view = $this->channelLookup->findByChannelName($event->channelName);
        if (null === $view) {
            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        $modesToSet = [];

        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter && !str_contains($view->modes, $registeredLetter)) {
            $modesToSet[] = $registeredLetter;
        }

        $permanentLetter = $modeSupport->getPermanentChannelModeLetter();
        if (null !== $permanentLetter && !str_contains($view->modes, $permanentLetter)) {
            $modesToSet[] = $permanentLetter;
        }

        if ([] === $modesToSet) {
            return;
        }

        $modeStr = '+' . implode('', $modesToSet);
        $this->channelServiceActions->setChannelModes($event->channelName, $modeStr, []);
        $this->logger->debug('ChanServ set modes on channel registration', [
            'channel' => $event->channelName,
            'modes' => $modeStr,
        ]);
    }

    public function onChannelDrop(ChannelDropEvent $event): void
    {
        $view = $this->channelLookup->findByChannelName($event->channelName);
        if (null === $view) {
            return;
        }

        $modeSupport = $this->modeSupportProvider->getSupport();
        $modesToRemove = [];

        $registeredLetter = $modeSupport->getChannelRegisteredModeLetter();
        if (null !== $registeredLetter && str_contains($view->modes, $registeredLetter)) {
            $modesToRemove[] = $registeredLetter;
        }

        $permanentLetter = $modeSupport->getPermanentChannelModeLetter();
        if (null !== $permanentLetter && str_contains($view->modes, $permanentLetter)) {
            $modesToRemove[] = $permanentLetter;
        }

        if ([] === $modesToRemove) {
            return;
        }

        $modeStr = '-' . implode('', $modesToRemove);
        $this->channelServiceActions->setChannelModes($event->channelName, $modeStr, []);
        $this->logger->debug('ChanServ removed modes on channel drop', [
            'channel' => $event->channelName,
            'modes' => $modeStr,
            'reason' => $event->reason,
        ]);
    }
}
