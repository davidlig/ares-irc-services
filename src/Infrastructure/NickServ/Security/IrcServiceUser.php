<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security;

use App\Domain\IRC\Network\NetworkUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Adapts NetworkUser to Symfony UserInterface for use in Security tokens.
 * Roles are derived from IRC modes: ROLE_IDENTIFIED (+r), ROLE_OPER (+o).
 */
final readonly class IrcServiceUser implements UserInterface
{
    public const ROLE_USER = 'ROLE_USER';

    public const ROLE_IDENTIFIED = 'ROLE_IDENTIFIED';

    public const ROLE_OPER = 'ROLE_OPER';

    public function __construct(
        private NetworkUser $networkUser,
    ) {
    }

    public function getRoles(): array
    {
        $roles = [self::ROLE_USER];

        if ($this->networkUser->isIdentified()) {
            $roles[] = self::ROLE_IDENTIFIED;
        }

        if ($this->networkUser->isOper()) {
            $roles[] = self::ROLE_OPER;
        }

        return $roles;
    }

    public function eraseCredentials(): void
    {
        // No credentials stored on this user.
    }

    public function getUserIdentifier(): string
    {
        return $this->networkUser->uid->value;
    }

    public function getNetworkUser(): NetworkUser
    {
        return $this->networkUser;
    }
}
