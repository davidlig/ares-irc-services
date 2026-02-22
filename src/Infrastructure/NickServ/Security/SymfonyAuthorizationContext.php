<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Security;

use App\Application\NickServ\Security\AuthorizationContextInterface;
use App\Domain\IRC\Network\NetworkUser;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Sets the current IRC user in Symfony Security TokenStorage so isGranted() can run.
 */
final readonly class SymfonyAuthorizationContext implements AuthorizationContextInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public function setCurrentUser(NetworkUser $user): void
    {
        $this->tokenStorage->setToken(new IrcServiceToken(new IrcServiceUser($user)));
    }

    public function clear(): void
    {
        $this->tokenStorage->setToken(null);
    }
}
