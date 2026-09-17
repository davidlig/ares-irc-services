<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Port\In\NickCollisionResolver;
use App\NickServ\Application\Port\Out\GuestNicknameGenerator;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickServActivitySink;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\UserDeidentifiedEvent;

use function sprintf;

/**
 * Centralized service for forcing a user to change to a Guest- nickname.
 *
 * Handles all necessary cleanup when forcing a rename:
 * - De-identifies the user (removes session from registry)
 * - Dispatches UserDeidentifiedEvent if user was identified
 * - Clears +r mode via protocol-specific setUserAccount()
 * - Clears any custom vhost
 * - Marks as pending restore to prevent protection loops
 * - Sends SVSNICK to force the nick change
 *
 * Used by: RenameCommand (IRCop), NickSuspensionService, NickProtectionService
 */
readonly class NickForceService implements NickCollisionResolver
{
    public function __construct(
        private IdentifiedSessionTracker $identifiedRegistry,
        private NickNetworkActions $notifier,
        private PendingNickRestoreRegistryInterface $pendingRegistry,
        private NickNetworkUserLookup $userLookup,
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickServEventPublisher $eventPublisher,
        private NickServActivitySink $logger,
        private GuestNicknameGenerator $guestNicknameGenerator,
        private string $guestPrefix = 'Guest-',
    ) {}

    /**
     * Forces a user to change to a Guest- nickname with full cleanup.
     *
     * @param string      $uid       UID of the user to rename
     * @param string|null $guestNick If null, generates automatically with configured prefix
     * @param string      $reason    Reason for the force (for logging): 'suspension', 'protection', 'ircop-rename'
     *
     * @return string|null the guest nickname applied, or null when the UID is no longer online
     */
    public function forceGuestNick(string $uid, ?string $guestNick = null, string $reason = 'enforcement'): ?string
    {
        if (null === $guestNick) {
            $guestNick = $this->guestNicknameGenerator->generate($this->guestPrefix);
        }

        $user = $this->userLookup->findByUid($uid);

        if (null === $user) {
            $this->logger->warning(sprintf(
                'NickForce: UID %s not found, cannot force rename',
                $uid,
            ));

            return null;
        }

        $identifiedNick = $this->identifiedRegistry->findNick($uid);

        if (null !== $identifiedNick) {
            $account = $this->nickRepository->findByNick($identifiedNick);
            if (null !== $account) {
                $this->eventPublisher->publish(new UserDeidentifiedEvent(
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

        return $guestNick;
    }
}
