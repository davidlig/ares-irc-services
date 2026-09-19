<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\In\IrcAuthorizationContext;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Sets the current IRC user in Symfony Security TokenStorage so isGranted() can run.
 */
final readonly class SymfonyAuthorizationContext implements AuthorizationContextInterface, IrcAuthorizationContext
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
    ) {}

    public function setCurrentUser(string $uid, bool $isIdentified, bool $isOper): void
    {
        $this->tokenStorage->setToken(new IrcServiceToken(new IrcServiceUser(new SenderView(
            uid: $uid,
            nick: '',
            ident: '',
            hostname: '',
            cloakedHost: '',
            ipBase64: '',
            isIdentified: $isIdentified,
            isOper: $isOper,
        ))));
    }

    public function clear(): void
    {
        $this->tokenStorage->setToken(null);
    }
}
