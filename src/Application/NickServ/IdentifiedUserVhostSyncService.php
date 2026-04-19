<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly NickServNotifierInterface $notifier,
        private readonly VhostDisplayResolver $displayResolver,
        private readonly OperIrcopRepositoryInterface $ircopRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Sync displayed vhost to the user's current identified state.
     * If identified (+r) and account has a vhost, apply it. If not identified, clear vhost
     * (e.g. after services reconnect, users who changed nick while services were down still
     * have the old vhost on the IRCd until we clear it).
     * Forced vhost from IRCop role takes priority over personal vhost.
     */
    public function syncVhostForUser(SenderView $user): void
    {
        if (!$user->isIdentified) {
            $this->notifier->setUserVhost($user->uid, '', $user->serverSid);

            return;
        }

        $account = $this->nickRepository->findByNick($user->nick);
        if (null === $account || !$account->isRegistered()) {
            return;
        }

        $ircop = $this->ircopRepository->findByNickId($account->getId());
        if (null !== $ircop) {
            $role = $ircop->getRole();
            $forcedPattern = $role->getForcedVhostPattern();

            if (null !== $forcedPattern && '' !== $forcedPattern && ForcedVhost::isValidPattern($forcedPattern)) {
                $forcedVhost = ForcedVhost::fromPattern($forcedPattern);
                $vhost = $forcedVhost->generateVhost($user->nick);

                $this->notifier->setUserVhost($user->uid, $vhost, $user->serverSid);
                $this->logger->info(sprintf(
                    'IdentifiedUserVhostSync: %s [%s] forced vhost applied (role: %s)',
                    $user->nick,
                    $user->uid,
                    $role->getName(),
                ));

                return;
            }
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
