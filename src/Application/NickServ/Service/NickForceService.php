<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Event\UserDeidentifiedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function sprintf;
use function str_starts_with;
use function strtoupper;
use function substr;
use function uniqid;

/**
 * Centralized service for forcing a user to change to a Guest- nickname.
 *
 * Handles all necessary cleanup when forcing a rename:
 * - De-identifies the user (removes session from registry)
 * - Dispatches UserDeidentifiedEvent if user was identified
 * - Clears +r mode via SVSLOGIN/SVS2MODE
 * - Clears any custom vhost
 * - Marks as pending restore to prevent protection loops
 * - Sends SVSNICK to force the nick change
 *
 * Used by: RenameCommand (IRCop), NickSuspensionService, NickProtectionService
 */
readonly class NickForceService
{
    public function __construct(
        private IdentifiedSessionRegistry $identifiedRegistry,
        private NickServNotifierInterface $notifier,
        private PendingNickRestoreRegistryInterface $pendingRegistry,
        private NetworkUserLookupPort $userLookup,
        private RegisteredNickRepositoryInterface $nickRepository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private string $guestPrefix = 'Guest-',
    ) {
    }

    /**
     * Forces a user to change to a Guest- nickname with full cleanup.
     *
     * @param string      $uid       UID of the user to rename
     * @param string|null $guestNick If null, generates automatically with configured prefix
     * @param string      $reason    Reason for the force (for logging): 'suspension', 'protection', 'ircop-rename'
     */
    public function forceGuestNick(string $uid, ?string $guestNick = null, string $reason = 'enforcement'): void
    {
        if (null === $guestNick) {
            if (str_starts_with($this->guestPrefix, 'Guest-')) {
                $guestNick = $this->guestPrefix . strtoupper(substr(uniqid(), -7));
            } else {
                $guestNick = $this->guestPrefix . strtoupper(substr(uniqid(), -7));
            }
        }

        $user = $this->userLookup->findByUid($uid);

        if (null === $user) {
            $this->logger->warning(sprintf(
                'NickForce: UID %s not found, cannot force rename',
                $uid,
            ));

            return;
        }

        $identifiedNick = $this->identifiedRegistry->findNick($uid);

        if (null !== $identifiedNick) {
            $account = $this->nickRepository->findByNick($identifiedNick);
            if (null !== $account) {
                $this->eventDispatcher->dispatch(new UserDeidentifiedEvent(
                    $uid,
                    $account->getId(),
                    $identifiedNick,
                ));
            }
            $this->identifiedRegistry->remove($uid);
        }

        $this->notifier->setUserAccount($uid, '0');
        $this->notifier->setUserVhost($uid, '', $user->serverSid);

        $this->pendingRegistry->mark($uid);

        $this->notifier->forceNick($uid, $guestNick);

        $this->logger->info(sprintf(
            'NickForce: %s [%s] forced to %s (reason: %s)',
            $user->nick,
            $uid,
            $guestNick,
            $reason,
        ));
    }
}
