<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\OperclassServiceActionsInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;

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
        private IdentifiedSessionRegistry $identifiedRegistry,
        private ActiveConnectionHolderInterface $connectionHolder,
        private OperIrcopRepositoryInterface $ircopRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {}

    /** Apply the role operclass when the active protocol supports it. */
    public function applyForNick(string $registeredNick, OperRole $role): bool
    {
        $operclass = $role->getOperclass();
        if (null === $operclass || '' === $operclass) {
            return false;
        }

        $uid = $this->identifiedRegistry->findUidByNick($registeredNick);
        $actions = null !== $uid ? $this->connectionHolder->getProtocolModule()?->getServiceActions() : null;
        if (null === $uid || !$actions instanceof OperclassServiceActionsInterface) {
            return false;
        }

        $actions->setUserOperclass(
            $this->connectionHolder->getServerSid(),
            $uid,
            $registeredNick,
            $operclass,
        );

        return true;
    }

    /** Remove an online user's operclass when their IRCOP assignment is removed. */
    public function removeForNick(string $registeredNick): bool
    {
        $uid = $this->identifiedRegistry->findUidByNick($registeredNick);
        $actions = null !== $uid ? $this->connectionHolder->getProtocolModule()?->getServiceActions() : null;
        if (null === $uid || !$actions instanceof OperclassServiceActionsInterface) {
            return false;
        }

        $actions->setUserOperclass(
            $this->connectionHolder->getServerSid(),
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
            $nick = $this->nickRepository->findById($ircop->getNickId());
            if (null === $nick) {
                continue;
            }

            if (null === $operclass || '' === $operclass) {
                $this->removeForNick($nick->getNickname());

                continue;
            }

            $this->applyForNick($nick->getNickname(), $ircop->getRole());
        }
    }
}
