<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\OperclassServiceActionsInterface;
use App\NickServ\Application\Port\In\IdentifiedSessionQuery;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;

/**
 * Applies role operclasses to online IRC operators through the active
 * protocol module.
 *
 * Feature-detects the optional OperclassServiceActionsInterface port: on
 * protocols without operclass support every call is a safe no-op. Lives
 * apart from IrcopModeApplier because operclass assignment is a capability
 * projection, not a user-mode change.
 */
final readonly class IrcopOperclassApplier
{
    public function __construct(
        private IdentifiedSessionQuery $identifiedSessions,
        private ActiveProtocolModuleHolderInterface $connectionHolder,
        private OperIrcopRepositoryInterface $ircopRepository,
        private NickProjectionQuery $nicks,
    ) {}

    /** Apply the role operclass when the active protocol supports it. */
    public function applyForNick(string $registeredNick, OperRole $role): bool
    {
        $operclass = $role->getOperclass();
        if (null === $operclass || '' === $operclass) {
            return false;
        }

        $uid = $this->identifiedSessions->findUidByNick($registeredNick);
        $actions = null !== $uid ? $this->connectionHolder->getProtocolModule()?->getServiceActions() : null;
        if (null === $uid || !$actions instanceof OperclassServiceActionsInterface) {
            return false;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            return false;
        }

        $actions->setUserOperclass(
            $serverSid,
            $uid,
            $registeredNick,
            $operclass,
        );

        return true;
    }

    /** Remove an online user's operclass when their IRCOP assignment is removed. */
    public function removeForNick(string $registeredNick): bool
    {
        $uid = $this->identifiedSessions->findUidByNick($registeredNick);
        $actions = null !== $uid ? $this->connectionHolder->getProtocolModule()?->getServiceActions() : null;
        if (null === $uid || !$actions instanceof OperclassServiceActionsInterface) {
            return false;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            return false;
        }

        $actions->setUserOperclass(
            $serverSid,
            $uid,
            $registeredNick,
            null,
        );

        return true;
    }

    /** Refresh all identified IRCOPs assigned to a role after its operclass changes. */
    public function updateForRole(int $roleId, ?string $operclass): void
    {
        foreach ($this->ircopRepository->findByRoleId($roleId) as $ircop) {
            $nick = $this->nicks->findById($ircop->getNickId());
            if (null === $nick) {
                continue;
            }

            if (null === $operclass || '' === $operclass) {
                $this->removeForNick($nick->nickname);

                continue;
            }

            $this->applyForNick($nick->nickname, $ircop->getRole());
        }
    }
}
