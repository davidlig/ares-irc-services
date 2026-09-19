<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickServActivitySink;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;

use function sprintf;

/**
 * Application service: syncs displayed vhost for users that are already identified (+r).
 * Used when a user is seen on the network (after burst or on join) so that reconnecting
 * services re-apply account vhost without coupling this to nick protection logic.
 * Respects forced vhost from OperServ roles (IRCops) - personal vhost is NOT applied
 * if the user has a forced vhost from their role.
 */
final readonly class IdentifiedUserVhostSyncService
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickNetworkActions $notifier,
        private VhostDisplayResolver $displayResolver,
        private ForcedVhostCheckerInterface $forcedVhostChecker,
        private NickChangeIdentificationPolicy $nickChangePolicy,
        private ?NickServActivitySink $logger = null,
    ) {}

    /**
     * Sync displayed vhost to the user's current identified state.
     * If identified (+r) and account has a vhost, apply it. If not identified, clear vhost
     * (e.g. after services reconnect, users who changed nick while services were down still
     * have the old vhost on the IRCd until we clear it).
     * Forced vhost from IRCop role takes priority over personal vhost.
     */
    public function syncVhostForUser(NetworkUser $user): void
    {
        if (!$user->isIdentified) {
            if (!$this->protocolHandlesVhostServerSide()) {
                $this->notifier->setUserVhost($user->uid, '', $user->serverSid);
            }

            return;
        }

        $account = $this->nickRepository->findByNick($user->nick);

        if (null === $account || !$account->isRegistered()) {
            return;
        }

        $this->applyVhostForUser($user, $account);
    }

    private function protocolHandlesVhostServerSide(): bool
    {
        return $this->nickChangePolicy->preservesIdentification();
    }

    private function applyVhostForUser(NetworkUser $user, RegisteredNick $account): void
    {
        $forcedVhost = $this->forcedVhostChecker->resolveForcedVhost($account->getId(), $user->nick);
        if (null !== $forcedVhost) {
            $this->notifier->setUserVhost($user->uid, $forcedVhost, $user->serverSid);
            $this->logger?->info(sprintf(
                'IdentifiedUserVhostSync: %s [%s] forced vhost applied',
                $user->nick,
                $user->uid,
            ));

            return;
        }

        $displayVhost = $this->displayResolver->getDisplayVhost($account->getVhost());

        if ('' === $displayVhost) {
            return;
        }

        $this->notifier->setUserVhost($user->uid, $displayVhost, $user->serverSid);
        $this->logger?->info(sprintf(
            'IdentifiedUserVhostSync: %s [%s] vhost applied',
            $user->nick,
            $user->uid,
        ));
    }
}
