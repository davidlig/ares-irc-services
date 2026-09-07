<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NickAuditSink;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickServActivitySink;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\NickTransactionBoundary;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\NickServ\Application\PublishedEvent\NickDropEvent;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;

use function sprintf;

/**
 * Centralized service for dropping registered nicknames.
 *
 * Handles all necessary cleanup when dropping a nick:
 * - If user is online, forces rename to Guest- (via NickForceService)
 * - For soft drops, marks the account pending deletion without cleanup events
 * - For hard drops, dispatches NickDropEvent and deletes from repository
 * - Logs to debug channel (if configured) and ircops.log
 *
 * Used by: DropCommand (IRCop), PurgeInactiveNicknamesTask (maintenance)
 */
readonly class NickDropService
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickNetworkUserLookup $userLookup,
        private NickForceService $forceService,
        private NickServEventPublisher $eventPublisher,
        private NickAuditSink $debug,
        private NickServActivitySink $logger,
        private IdentifiedSessionTracker $sessionRegistry,
        private NickTransactionBoundary $transactionBoundary,
        private string $guestPrefix = 'Guest-',
    ) {}

    /**
     * Starts a recoverable manual drop without cleaning dependent data.
     */
    public function softDropNick(
        RegisteredNick $account,
        DateTimeImmutable $occurredAt,
        ?string $operatorNick = null,
    ): void {
        $nickname = $account->getNickname();
        $onlineUser = $this->userLookup->findByNick($nickname);

        if (null !== $onlineUser) {
            $this->forceService->forceGuestNick($onlineUser->uid, null, 'nick-drop');
            $this->sessionRegistry->remove($onlineUser->uid);
        } else {
            $uid = $this->sessionRegistry->findUidByNick($nickname);
            if (null !== $uid) {
                $this->sessionRegistry->remove($uid);
            }
        }

        $account->markPendingDeletion($occurredAt);
        $this->nickRepository->save($account);

        $this->debug->log(
            operator: $operatorNick ?? '*',
            command: 'DROP',
            target: $nickname,
            reason: 'manual',
            extra: ['soft_delete' => true, 'was_online' => null !== $onlineUser],
        );

        $this->logger->info(sprintf(
            'NickDrop: %s (id %d) marked pending deletion. Online: %s. Operator: %s.',
            $nickname,
            $account->getId(),
            null !== $onlineUser ? 'yes' : 'no',
            $operatorNick ?? 'maintenance',
        ));
    }

    public function restoreNick(RegisteredNick $account, ?string $operatorNick = null): void
    {
        $account->restoreFromPendingDeletion();
        $this->nickRepository->save($account);

        $this->debug->log(
            operator: $operatorNick ?? '*',
            command: 'RESTORE',
            target: $account->getNickname(),
            reason: 'manual',
        );

        $this->logger->info(sprintf(
            'NickRestore: %s (id %d) restored from pending deletion. Operator: %s.',
            $account->getNickname(),
            $account->getId(),
            $operatorNick ?? 'maintenance',
        ));
    }

    /**
     * Permanently drops a registered nickname with full cleanup.
     *
     * @param RegisteredNick $account      The account to drop
     * @param string         $reason       Drop reason: 'manual' (IRCop) or 'inactivity' (maintenance)
     * @param string|null    $operatorNick Operator nickname for debug logging (null for maintenance)
     */
    public function hardDropNick(
        RegisteredNick $account,
        DateTimeImmutable $occurredAt,
        string $reason = 'manual',
        ?string $operatorNick = null,
    ): void {
        $nickId = $account->getId();
        $nickname = $account->getNickname();
        $nicknameLower = $account->getNicknameLower();

        $cleanupEvent = new NickDropCleanupEvent(
            $nickId,
            $nickname,
            $nicknameLower,
            $reason,
            $occurredAt,
        );

        $this->transactionBoundary->transactional(function () use ($cleanupEvent, $account): void {
            $this->eventPublisher->publish($cleanupEvent);
            $this->nickRepository->delete($account);
        });

        $onlineUser = $this->userLookup->findByNick($nickname);
        if (null !== $onlineUser) {
            $this->forceService->forceGuestNick($onlineUser->uid, null, 'nick-drop');
        }

        $this->eventPublisher->publish(new NickDropEvent(
            $nickId,
            $nickname,
            $nicknameLower,
            $reason,
            $cleanupEvent->occurredAt,
        ));

        $this->debug->log(
            operator: $operatorNick ?? '*',
            command: 'DROP',
            target: $nickname,
            reason: $reason,
            extra: ['was_online' => null !== $onlineUser],
        );

        $this->logger->info(sprintf(
            'NickDrop: %s (id %d) dropped. Reason: %s. Online: %s. Operator: %s.',
            $nickname,
            $nickId,
            $reason,
            null !== $onlineUser ? 'yes' : 'no',
            $operatorNick ?? 'maintenance',
        ));
    }

    public function dropNick(
        RegisteredNick $account,
        DateTimeImmutable $occurredAt,
        string $reason = 'manual',
        ?string $operatorNick = null,
    ): void {
        $this->hardDropNick($account, $occurredAt, $reason, $operatorNick);
    }

    public function getGuestPrefix(): string
    {
        return $this->guestPrefix;
    }
}
