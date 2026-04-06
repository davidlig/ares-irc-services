<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\NickServ\BurstState;
use App\Application\NickServ\IdentifiedUserVhostSyncService;
use App\Application\NickServ\NickProtectionService;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber: forwards Application-layer events to NickServ services.
 * Receives UserJoinedNetworkAppEvent (DTO-based) instead of Core Domain events.
 * Orchestrates burst (BurstState) and calls IdentifiedUserVhostSync then NickProtection
 * so vhost sync and protection stay in separate application services.
 */
final readonly class NickProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NickProtectionService $nickProtectionService,
        private readonly IdentifiedUserVhostSyncService $identifiedUserVhostSync,
        private readonly BurstState $burstState,
        private readonly NetworkUserLookupPort $networkUserLookup,
    ) {
    }

    /**
     * Priorities per Symfony 7.4 event_dispatcher: higher = runs earlier; range -256..256.
     *
     * @see https://symfony.com/doc/7.4/event_dispatcher.html
     */
    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedNetworkAppEvent::class => ['onUserJoined', 0],
            UserQuitNetworkEvent::class => ['onUserQuit', 0],
            UserNickChangedEvent::class => ['onNickChanged', 0],
            NetworkBurstCompleteEvent::class => ['onBurstComplete', -256],
        ];
    }

    public function onUserJoined(UserJoinedNetworkAppEvent $event): void
    {
        $senderView = $this->networkUserLookup->findByUid($event->user->uid);
        if (null === $senderView) {
            return;
        }

        if (!$this->burstState->isComplete()) {
            $this->burstState->addPending($senderView);

            return;
        }

        $this->identifiedUserVhostSync->syncVhostForUser($senderView);
        $this->nickProtectionService->onUserJoined($senderView);
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->burstState->markComplete();
        $pending = $this->burstState->takePending();

        foreach ($pending as $user) {
            $this->identifiedUserVhostSync->syncVhostForUser($user);
            $this->nickProtectionService->enforceProtection($user);
        }
    }

    public function onNickChanged(UserNickChangedEvent $event): void
    {
        $this->nickProtectionService->onNickChanged(
            $event->uid->value,
            $event->oldNick->value,
            $event->newNick->value,
        );
    }

    public function onUserQuit(UserQuitNetworkEvent $event): void
    {
        $this->nickProtectionService->onUserQuit(
            $event->uid->value,
            $event->nick->value,
            $event->reason,
            $event->ident,
            $event->displayHost,
            $event->hostname,
            $event->ipBase64,
        );
    }
}
