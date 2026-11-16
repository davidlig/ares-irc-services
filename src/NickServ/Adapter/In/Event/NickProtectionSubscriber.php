<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NativeNicknameAuthenticationInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\NickServ\Adapter\Out\User\IrcNetworkUserMapper;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PendingNickProtectionRegistryInterface;
use App\NickServ\Application\PublishedEvent\NickBurstBatchFinishedEvent;
use App\NickServ\Application\PublishedEvent\NickBurstBatchStartedEvent;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\IdentifiedUserVhostSyncService;
use App\NickServ\Application\Service\NickProtectionService;
use App\NickServ\Application\Service\PendingNickActivities;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentification;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentificationHandlerInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function sprintf;
use function str_contains;

/**
 * Subscriber: forwards Application-layer events to NickServ services.
 * Receives UserJoinedNetworkAppEvent (DTO-based) instead of Core Domain events.
 * Orchestrates burst (BurstState) and calls IdentifiedUserVhostSync then NickProtection
 * so vhost sync and protection stay in separate application services.
 *
 * @phpstan-type BurstChunkProgressShape array{handledUsers: int, userInProgress: bool, userFailed: bool, unsavedActivityNicknames: list<string>}
 */
final readonly class NickProtectionSubscriber implements EventSubscriberInterface
{
    private const int BURST_CHUNK_SIZE = 200;

    public function __construct(
        private NickProtectionService $nickProtectionService,
        private IdentifiedUserVhostSyncService $identifiedUserVhostSync,
        private BurstState $burstState,
        private NetworkUserLookupPort $networkUserLookup,
        private ActiveProtocolModuleHolderInterface $connectionHolder,
        private Clock $clock,
        private SyncNetworkIdentificationHandlerInterface $syncNetworkIdentification,
        private ?PendingNickProtectionRegistryInterface $pendingProtectionRegistry = null,
        private PendingNickActivities $pendingQuitActivities = new PendingNickActivities(),
        private ?NickServEventPublisher $batchEventPublisher = null,
        private LoggerInterface $logger = new NullLogger(),
        private ?EntityManagerInterface $entityManager = null,
        private ?ManagerRegistry $managerRegistry = null,
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
            NetworkConnectionLostEvent::class => ['onConnectionLost', 0],
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
        // The burst users already left the pending queue, so a closed EntityManager is reset once
        // and the pass continues; the next chunk reloads its accounts from the fresh manager.
        $pending = $this->burstState->takePending();
        $offset = 0;
        $chunk = 0;
        $resetDone = false;

        while ($offset < count($pending)) {
            $batch = array_values(array_slice($pending, $offset, self::BURST_CHUNK_SIZE));
            ++$chunk;
            $progress = ['handledUsers' => 0, 'userInProgress' => false, 'userFailed' => false, 'unsavedActivityNicknames' => []];
            try {
                $this->protectBurstChunk($batch, $occurredAt, $chunk, $progress);
            } catch (Throwable $exception) {
                if ($this->isEntityManagerClosed()) {
                    $remainingUsers = count($pending) - $offset - $progress['handledUsers'];
                    if (!$resetDone && $this->resetClosedEntityManager($chunk)) {
                        $resetDone = true;
                        $offset += $this->continueAfterReset($remainingUsers, $batch, $progress, $chunk, $occurredAt, $exception);

                        continue;
                    }

                    // Every later user would fail against the closed EntityManager; with no reset left, stop the pass once.
                    // The error is the failure signal: the pass ends here without throwing out of the listener.
                    $this->logger->error('NickServ burst protection stopped: the EntityManager is closed, so the remaining burst users are left unprotected.', [
                        'chunk' => $chunk,
                        'remaining_users' => $remainingUsers,
                        'exception' => $exception,
                    ]);

                    return;
                }

                // One failing chunk must not leave every later chunk unprotected.
                $this->logger->error('NickServ burst protection chunk failed; continuing with the next chunk.', [
                    'chunk' => $chunk,
                    'users' => count($batch),
                    'exception' => $exception,
                ]);
            }
            $offset += count($batch);
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

    public function onUserQuit(UserLeftNetworkEvent $event): void
    {
        $this->pendingProtectionRegistry?->cancel($event->uid);

        $account = $this->nickProtectionService->onUserQuit(
            $event->uid,
            $event->nickname,
            $event->reason,
            $event->ident,
            $event->displayHost,
            $event->hostname,
            $event->ipBase64,
            $this->clock->now(),
            true,
        );
        if (null !== $account) {
            $this->pendingQuitActivities->add($account);
        }
    }

    public function onIrcMessageProcessed(IrcMessageHandledEvent $event): void
    {
        $this->flushQuitActivities();

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

    public function onConnectionLost(NetworkConnectionLostEvent $event): void
    {
        $this->flushQuitActivities();
    }

    /** Resets the closed EntityManager so the pass can continue; false when no reset was possible. */
    private function resetClosedEntityManager(int $chunk): bool
    {
        if (null === $this->managerRegistry) {
            return false;
        }

        try {
            $this->managerRegistry->resetManager();
        } catch (Throwable $exception) {
            $this->logger->error('NickServ burst protection could not reset the closed EntityManager.', [
                'chunk' => $chunk,
                'exception' => $exception,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Continues the pass on the fresh EntityManager and returns how far the offset moves.
     *
     * When protecting one user closed the EntityManager, that user is skipped, so a user that always
     * fails neither closes the fresh manager again nor repeats its side effects. Otherwise the pass
     * resumes at the first user not handled yet. Either way, the last-seen activity the closed
     * manager did not save is saved again from accounts reloaded by nickname.
     *
     * @param int                     $remainingUsers burst users not handled yet, the failing user included
     * @param list<NetworkUser>       $batch
     * @param BurstChunkProgressShape $progress
     */
    private function continueAfterReset(int $remainingUsers, array $batch, array $progress, int $chunk, DateTimeImmutable $occurredAt, Throwable $exception): int
    {
        if (!$progress['userFailed']) {
            $this->logger->warning('NickServ burst protection: the EntityManager closed, resetting it and protecting the remaining burst users.', [
                'chunk' => $chunk,
                'remaining_users' => $remainingUsers,
                'exception' => $exception,
            ]);
            $this->saveActivitiesAgain($progress['unsavedActivityNicknames'], $chunk, $occurredAt);

            return $progress['handledUsers'];
        }

        $this->logger->warning('NickServ burst protection: the EntityManager closed, resetting it, skipping the user whose protection closed it and protecting the remaining burst users.', [
            'chunk' => $chunk,
            'uid' => $batch[$progress['handledUsers']]->uid,
            'remaining_users' => $remainingUsers - 1,
            'exception' => $exception,
        ]);
        $this->saveActivitiesAgain($progress['unsavedActivityNicknames'], $chunk, $occurredAt);

        return $progress['handledUsers'] + 1;
    }

    /**
     * The accounts of the closed EntityManager are detached, so they are reloaded by nickname.
     *
     * @param list<string> $nicknames
     */
    private function saveActivitiesAgain(array $nicknames, int $chunk, DateTimeImmutable $occurredAt): void
    {
        if ([] === $nicknames) {
            return;
        }

        try {
            $accounts = array_values($this->identifiedUserVhostSync->findAccountsForBurst($nicknames));
            foreach ($accounts as $account) {
                $account->markSeen($occurredAt);
            }
            $this->nickProtectionService->saveActivities($accounts);
        } catch (Throwable $exception) {
            $this->logger->error('NickServ burst protection could not save the last-seen activity of the users handled before the EntityManager closed.', [
                'chunk' => $chunk,
                'users' => count($nicknames),
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Saves the chunk's activity and ends its batch even when protection fails. The first failure
     * is rethrown; a later cleanup failure is logged so it neither hides nor replaces it.
     *
     * @param list<NetworkUser>       $batch
     * @param BurstChunkProgressShape $progress filled with how far protection got, for the caller's recovery
     */
    private function protectBurstChunk(array $batch, DateTimeImmutable $occurredAt, int $chunk, array &$progress): void
    {
        $accounts = $this->identifiedUserVhostSync->findAccountsForBurst(array_map(static fn (NetworkUser $user): string => $user->nick, $batch));
        $activityBatch = new PendingNickActivities();
        $ids = [];
        foreach ($accounts as $account) {
            $ids[$account->getId()] = $account->getId();
        }
        $ids = array_values($ids);
        $batchStarted = new NickBurstBatchStartedEvent($ids);
        $failure = null;
        try {
            $this->batchEventPublisher?->publish($batchStarted);
            foreach ($batch as $user) {
                // The user is blamed for a closed EntityManager only when it was open as its protection began.
                $progress['userInProgress'] = true;
                $progress['userFailed'] = !$this->isEntityManagerClosed();
                $this->protectBurstUser($user, $accounts[$user->nick] ?? null, $occurredAt, $activityBatch, $chunk);
                $progress['userInProgress'] = false;
                $progress['userFailed'] = false;
                ++$progress['handledUsers'];
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $failure = $this->finishBurstChunkStep($failure, $chunk, 'save its activity', function () use ($activityBatch, $chunk, $batch, $accounts, &$progress): void {
            $activities = $activityBatch->all();
            if ([] === $activities) {
                return;
            }
            $progress['unsavedActivityNicknames'] = $this->handledActivityNicknames($activities, $batch, $accounts, $progress);
            if ($this->isEntityManagerClosed()) {
                $this->logger->warning(sprintf('NickServ burst protection did not save the last-seen activity of %d users: the EntityManager is closed.', count($activities)), [
                    'chunk' => $chunk,
                ]);

                return;
            }
            // Kept until the save succeeds: a save that closes the EntityManager is repeated after the reset.
            $this->nickProtectionService->saveActivities($activities);
            $progress['unsavedActivityNicknames'] = [];
        });
        $failure = $this->finishBurstChunkStep($failure, $chunk, 'end its batch', function () use ($batchStarted): void {
            $this->batchEventPublisher?->publish(new NickBurstBatchFinishedEvent($batchStarted->token));
        });
        if (null !== $failure) {
            throw $failure;
        }
    }

    /**
     * Nicknames of the activities collected for users whose protection ran to an outcome, so the
     * user whose protection was interrupted by the closed EntityManager is left out: it is either
     * skipped or protected again.
     *
     * @param list<RegisteredNick>          $activities
     * @param list<NetworkUser>             $batch
     * @param array<string, RegisteredNick> $accounts
     * @param BurstChunkProgressShape       $progress
     *
     * @return list<string>
     */
    private function handledActivityNicknames(array $activities, array $batch, array $accounts, array $progress): array
    {
        $interruptedAccount = $progress['userInProgress'] ? ($accounts[$batch[$progress['handledUsers']]->nick] ?? null) : null;
        $nicknames = [];
        foreach ($activities as $account) {
            if ($account !== $interruptedAccount) {
                $nicknames[] = $account->getNickname();
            }
        }

        return $nicknames;
    }

    /**
     * One failing user is logged and skipped so the rest of its chunk is still protected,
     * unless the failure closed the EntityManager: then the chunk stops so the pass can reset it once or stop.
     */
    private function protectBurstUser(NetworkUser $user, ?RegisteredNick $account, DateTimeImmutable $occurredAt, PendingNickActivities $activityBatch, int $chunk): void
    {
        try {
            $this->identifiedUserVhostSync->syncVhostForLoadedUser($user, $account);
            $this->nickProtectionService->enforceProtectionWithAccount($user, $occurredAt, $account, $activityBatch);
        } catch (Throwable $exception) {
            if ($this->isEntityManagerClosed()) {
                throw $exception;
            }

            $this->logger->error('NickServ burst protection failed for one user; continuing with the rest of the chunk.', [
                'chunk' => $chunk,
                'uid' => $user->uid,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @param Closure(): void $run
     */
    private function finishBurstChunkStep(?Throwable $failure, int $chunk, string $step, Closure $run): ?Throwable
    {
        try {
            $run();
        } catch (Throwable $exception) {
            if (null === $failure) {
                return $exception;
            }
            $this->logger->error(sprintf('NickServ burst protection chunk could not %s after the chunk failed.', $step), [
                'chunk' => $chunk,
                'exception' => $exception,
            ]);
        }

        return $failure;
    }

    /** A closed EntityManager cannot serve any later user of the burst. */
    private function isEntityManagerClosed(): bool
    {
        return null !== $this->entityManager && !$this->entityManager->isOpen();
    }

    private function flushQuitActivities(): void
    {
        $accounts = $this->pendingQuitActivities->all();
        if ([] !== $accounts) {
            try {
                $this->nickProtectionService->saveActivities($accounts);
            } finally {
                $this->pendingQuitActivities->clear();
            }
        }
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
