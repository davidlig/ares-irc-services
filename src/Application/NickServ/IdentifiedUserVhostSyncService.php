<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function sprintf;

/**
 * Application service: syncs displayed vhost for users that are already identified (+r).
 * Used when a user is seen on the network (after burst or on join) so that reconnecting
 * services re-apply account vhost without coupling this to nick protection logic.
 */
final readonly class IdentifiedUserVhostSyncService
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickServNotifierInterface $notifier,
        private readonly VhostDisplayResolver $displayResolver,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * If the user is identified and has an account with a vhost, apply it (display form with suffix).
     * No-op otherwise.
     */
    public function syncVhostForUser(SenderView $user): void
    {
        if (!$user->isIdentified) {
            return;
        }

        $account = $this->nickRepository->findByNick($user->nick);
        if (null === $account || !$account->isRegistered()) {
            return;
        }

        $displayVhost = $this->displayResolver->getDisplayVhost($account->getVhost());
        if ('' === $displayVhost) {
            return;
        }

        $this->notifier->setUserVhost($user->uid, $displayVhost, $user->serverSid);
        $this->logger->info(sprintf(
            'IdentifiedUserVhostSync: %s [%s] vhost applied',
            $user->nick,
            $user->uid,
        ));
    }
}
