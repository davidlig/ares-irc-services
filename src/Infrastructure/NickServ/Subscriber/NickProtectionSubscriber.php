<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\BurstState;
use App\Application\NickServ\IdentifiedUserVhostSyncService;
use App\Application\NickServ\NickProtectionService;
use App\Application\NickServ\PendingNickProtectionRegistryInterface;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NickChangePreservesIdentificationInterface;
use App\Irc\Adapter\Event\IrcMessageProcessedEvent;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\Event\UserNickChangedEvent;
use App\Irc\Domain\Event\UserQuitNetworkEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function str_contains;

/**
 * Subscriber: forwards Application-layer events to NickServ services.
 * Receives UserJoinedNetworkAppEvent (DTO-based) instead of Core Domain events.
 * Orchestrates burst (BurstState) and calls IdentifiedUserVhostSync then NickProtection
 * so vhost sync and protection stay in separate application services.
 */
final readonly class NickProtectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NickProtectionService $nickProtectionService,
        private IdentifiedUserVhostSyncService $identifiedUserVhostSync,
        private BurstState $burstState,
        private NetworkUserLookupPort $networkUserLookup,
        private ActiveConnectionHolderInterface $connectionHolder,
        private ?PendingNickProtectionRegistryInterface $pendingProtectionRegistry = null,
    ) {}

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
            UserModeChangedEvent::class => ['onUserModeChanged', 0],
            NetworkBurstCompleteEvent::class => ['onBurstComplete', -256],
            IrcMessageProcessedEvent::class => ['onIrcMessageProcessed', -200],
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

        if ($senderView->isIdentified) {
            $this->identifiedUserVhostSync->syncVhostForUser($senderView);
            $this->nickProtectionService->onUserJoined($senderView);

            return;
        }

        if ($this->shouldDeferProtectionCheck() && null !== $this->pendingProtectionRegistry) {
            $this->pendingProtectionRegistry->schedule($event->user->uid);

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
        $senderView = $this->networkUserLookup->findByUid($event->uid->value);

        if (null !== $senderView && $senderView->isIdentified) {
            $this->nickProtectionService->onNickChanged(
                $event->uid->value,
                $event->oldNick->value,
                $event->newNick->value,
            );
            if (!$this->shouldDeferProtectionCheck()) {
                $this->identifiedUserVhostSync->syncVhostForUser($senderView);
            }

            return;
        }

        if ($this->shouldDeferProtectionCheck() && null !== $this->pendingProtectionRegistry) {
            $this->pendingProtectionRegistry->schedule($event->uid->value);

            return;
        }

        $this->nickProtectionService->onNickChanged(
            $event->uid->value,
            $event->oldNick->value,
            $event->newNick->value,
        );

        if (null !== $senderView) {
            $this->identifiedUserVhostSync->syncVhostForUser($senderView);
        }
    }

    public function onUserModeChanged(UserModeChangedEvent $event): void
    {
        if (!str_contains($event->modeDelta, 'r') || str_contains($event->modeDelta, '-r')) {
            return;
        }

        $this->pendingProtectionRegistry?->cancel($event->uid->value);

        $senderView = $this->networkUserLookup->findByUid($event->uid->value);
        if (null === $senderView || !$senderView->isIdentified) {
            return;
        }

        $this->identifiedUserVhostSync->syncVhostForUser($senderView);
        $this->nickProtectionService->enforceProtection($senderView);
    }

    public function onUserQuit(UserQuitNetworkEvent $event): void
    {
        $this->pendingProtectionRegistry?->cancel($event->uid->value);

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

    public function onIrcMessageProcessed(IrcMessageProcessedEvent $event): void
    {
        if (null === $this->pendingProtectionRegistry) {
            return;
        }

        $expiredUids = $this->pendingProtectionRegistry->flushExpired();
        foreach ($expiredUids as $uid) {
            $senderView = $this->networkUserLookup->findByUid($uid);
            if (null === $senderView) {
                continue;
            }

            if ($senderView->isIdentified) {
                $this->identifiedUserVhostSync->syncVhostForUser($senderView);
            }

            $this->nickProtectionService->enforceProtection($senderView);
        }
    }

    private function shouldDeferProtectionCheck(): bool
    {
        $module = $this->connectionHolder->getProtocolModule();

        return $module instanceof NickChangePreservesIdentificationInterface;
    }
}
