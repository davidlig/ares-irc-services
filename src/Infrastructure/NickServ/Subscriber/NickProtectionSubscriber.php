<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\BurstState;
use App\Application\NickServ\IdentifiedUserVhostSyncService;
use App\Application\NickServ\NickProtectionService;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Infrastructure\IRC\ServiceBridge\CoreNetworkUserLookupAdapter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber: forwards IRC domain events to NickServ application services.
 * Orchestrates burst (BurstState) and calls IdentifiedUserVhostSync then NickProtection
 * so vhost sync and protection stay in separate application services.
 */
final readonly class NickProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NickProtectionService $nickProtectionService,
        private readonly IdentifiedUserVhostSyncService $identifiedUserVhostSync,
        private readonly BurstState $burstState,
        private readonly CoreNetworkUserLookupAdapter $senderViewMapper,
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
            UserJoinedNetworkEvent::class => ['onUserJoined', 0],
            UserQuitNetworkEvent::class => ['onUserQuit', 0],
            UserNickChangedEvent::class => ['onNickChanged', 0],
            NetworkBurstCompleteEvent::class => ['onBurstComplete', -256],
        ];
    }

    public function onUserJoined(UserJoinedNetworkEvent $event): void
    {
        $senderView = $this->senderViewMapper->fromNetworkUser($event->user);

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
        $this->nickProtectionService->onNickChanged($event);
    }

    public function onUserQuit(UserQuitNetworkEvent $event): void
    {
        $this->nickProtectionService->onUserQuit($event);
    }
}
