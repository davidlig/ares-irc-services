<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Sets the current IRC user in Symfony Security TokenStorage so isGranted() can run.
 */
final readonly class SymfonyAuthorizationContext implements AuthorizationContextInterface
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {}

    public function setCurrentUser(SenderView $user): void
    {
        $this->tokenStorage->setToken(new IrcServiceToken(new IrcServiceUser($user)));
    }

    public function clear(): void
    {
        $this->tokenStorage->setToken(null);
    }
}
