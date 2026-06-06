<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\Port\EventBusInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;

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
        private NetworkUserLookupPort $userLookup,
        private NickForceService $forceService,
        private EventBusInterface $eventDispatcher,
        private ServiceDebugNotifierInterface $debug,
        private LoggerInterface $logger,
        private IdentifiedSessionRegistry $sessionRegistry,
        private string $guestPrefix = 'Guest-',
    ) {}

    /**
     * Starts a recoverable manual drop without cleaning dependent data.
     */
    public function softDropNick(
        RegisteredNick $account,
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

        $account->markPendingDeletion();
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
        string $reason = 'manual',
        ?string $operatorNick = null,
    ): void {
        $nickId = $account->getId();
        $nickname = $account->getNickname();
        $nicknameLower = $account->getNicknameLower();

        $onlineUser = $this->userLookup->findByNick($nickname);

        if (null !== $onlineUser) {
            $this->forceService->forceGuestNick($onlineUser->uid, null, 'nick-drop');
        }

        $this->eventDispatcher->dispatch(new NickDropEvent(
            $nickId,
            $nickname,
            $nicknameLower,
            $reason,
        ));

        $this->nickRepository->delete($account);

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
        string $reason = 'manual',
        ?string $operatorNick = null,
    ): void {
        $this->hardDropNick($account, $reason, $operatorNick);
    }
}
