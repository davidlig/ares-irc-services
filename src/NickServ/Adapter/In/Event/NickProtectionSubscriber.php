<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NativeNicknameAuthenticationInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\NickServ\Adapter\Out\User\IrcNetworkUserMapper;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\PendingNickProtectionRegistryInterface;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\IdentifiedUserVhostSyncService;
use App\NickServ\Application\Service\NickProtectionService;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentification;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentificationHandlerInterface;
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
        private ActiveProtocolModuleHolderInterface $connectionHolder,
        private Clock $clock,
        private SyncNetworkIdentificationHandlerInterface $syncNetworkIdentification,
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
            UserLeftNetworkEvent::class => ['onUserQuit', 0],
            UserNicknameChangedEvent::class => ['onNickChanged', 0],
            UserModesChangedEvent::class => ['onUserModeChanged', 0],
            NetworkSynchronizationCompletedEvent::class => ['onBurstComplete', -256],
            IrcMessageHandledEvent::class => ['onIrcMessageProcessed', -200],
        ];
    }

    public function onUserJoined(UserJoinedNetworkAppEvent $event): void
    {
        $occurredAt = $this->clock->now();
        $senderView = $this->networkUserLookup->findByUid($event->user->uid);
        if (null === $senderView) {
            return;
        }

        $user = IrcNetworkUserMapper::map($senderView);

        if (!$this->burstState->isComplete()) {
            $this->burstState->addPending($user);

            return;
        }

        if ($senderView->isIdentified) {
            $this->identifiedUserVhostSync->syncVhostForUser($user);
            $this->nickProtectionService->onUserJoined($user, $occurredAt);

            return;
        }

        if ($this->shouldDeferProtectionCheck() && null !== $this->pendingProtectionRegistry) {
            $this->pendingProtectionRegistry->schedule($event->user->uid, $occurredAt);

            return;
        }

        $this->identifiedUserVhostSync->syncVhostForUser($user);
        $this->nickProtectionService->onUserJoined($user, $occurredAt);
    }

    public function onBurstComplete(NetworkSynchronizationCompletedEvent $event): void
    {
        $occurredAt = $this->clock->now();
        $this->burstState->markComplete();
        $pending = $this->burstState->takePending();

        foreach ($pending as $user) {
            $this->identifiedUserVhostSync->syncVhostForUser($user);
            $this->nickProtectionService->enforceProtection($user, $occurredAt);
        }
    }

    public function onNickChanged(UserNicknameChangedEvent $event): void
    {
        $occurredAt = $this->clock->now();
        $senderView = $this->networkUserLookup->findByUid($event->uid);

        if (null !== $senderView && $senderView->isIdentified) {
            $this->nickProtectionService->onNickChanged(
                $event->uid,
                $event->oldNickname,
                $event->newNickname,
                $occurredAt,
            );
            if (!$this->shouldDeferProtectionCheck()) {
                $this->identifiedUserVhostSync->syncVhostForUser(IrcNetworkUserMapper::map($senderView));
            }

            return;
        }

        if ($this->shouldDeferProtectionCheck() && null !== $this->pendingProtectionRegistry) {
            $this->pendingProtectionRegistry->schedule($event->uid, $occurredAt);

            return;
        }

        $this->nickProtectionService->onNickChanged(
            $event->uid,
            $event->oldNickname,
            $event->newNickname,
            $occurredAt,
        );

        if (null !== $senderView) {
            $this->identifiedUserVhostSync->syncVhostForUser(IrcNetworkUserMapper::map($senderView));
        }
    }

    public function onUserModeChanged(UserModesChangedEvent $event): void
    {
        if (!str_contains($event->modeDelta, 'r')) {
            return;
        }

        if (!$this->usesNativeAuthentication()) {
            $this->handleServiceAuthenticationModeChange($event);

            return;
        }

        $this->pendingProtectionRegistry?->cancel($event->uid);

        $senderView = $this->networkUserLookup->findByUid($event->uid);
        if (null === $senderView) {
            return;
        }

        $user = IrcNetworkUserMapper::map($senderView);
        if ($user->isIdentified) {
            $this->identifiedUserVhostSync->syncVhostForUser($user);
        }

        $this->syncNetworkIdentification->handle(new SyncNetworkIdentification($user, $this->clock->now()));
    }

    private function handleServiceAuthenticationModeChange(UserModesChangedEvent $event): void
    {
        if (str_contains($event->modeDelta, '-r')) {
            return;
        }

        $this->pendingProtectionRegistry?->cancel($event->uid);

        $senderView = $this->networkUserLookup->findByUid($event->uid);
        if (null === $senderView || !$senderView->isIdentified) {
            return;
        }

        $user = IrcNetworkUserMapper::map($senderView);
        $this->identifiedUserVhostSync->syncVhostForUser($user);
        $this->nickProtectionService->enforceProtection($user, $this->clock->now());
    }

    public function onUserQuit(UserLeftNetworkEvent $event): void
    {
        $this->pendingProtectionRegistry?->cancel($event->uid);

        $this->nickProtectionService->onUserQuit(
            $event->uid,
            $event->nickname,
            $event->reason,
            $event->ident,
            $event->displayHost,
            $event->hostname,
            $event->ipBase64,
            $this->clock->now(),
        );
    }

    public function onIrcMessageProcessed(IrcMessageHandledEvent $event): void
    {
        if (null === $this->pendingProtectionRegistry) {
            return;
        }

        $expiredUids = $this->pendingProtectionRegistry->flushExpired($this->clock->now());
        foreach ($expiredUids as $uid) {
            $senderView = $this->networkUserLookup->findByUid($uid);
            if (null === $senderView) {
                continue;
            }

            if ($senderView->isIdentified) {
                $this->identifiedUserVhostSync->syncVhostForUser(IrcNetworkUserMapper::map($senderView));
            }

            $this->nickProtectionService->enforceProtection(IrcNetworkUserMapper::map($senderView), $this->clock->now());
        }
    }

    private function shouldDeferProtectionCheck(): bool
    {
        $module = $this->connectionHolder->getProtocolModule();

        return $module instanceof NickChangePreservesIdentificationInterface;
    }

    private function usesNativeAuthentication(): bool
    {
        return $this->connectionHolder->getProtocolModule() instanceof NativeNicknameAuthenticationInterface;
    }
}
