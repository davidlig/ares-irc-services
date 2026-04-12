<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Centralized service for dropping registered nicknames.
 *
 * Handles all necessary cleanup when dropping a nick:
 * - If user is online, forces rename to Guest- (via NickForceService)
 * - Dispatches NickDropEvent for cleanup by other services (ChanServ, MemoServ, OperServ)
 * - Deletes from repository
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
        private EventDispatcherInterface $eventDispatcher,
        private ServiceDebugNotifierInterface $debug,
        private LoggerInterface $logger,
        private string $guestPrefix = 'Guest-',
    ) {
    }

    /**
     * Drops a registered nickname with full cleanup.
     *
     * @param RegisteredNick $account      The account to drop
     * @param string         $reason       Drop reason: 'manual' (IRCop) or 'inactivity' (maintenance)
     * @param string|null    $operatorNick Operator nickname for debug logging (null for maintenance)
     */
    public function dropNick(
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
}
