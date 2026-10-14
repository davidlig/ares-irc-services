<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\Out\Security;

use App\CRegServ\Adapter\In\Irc\CRegServAuthorizationContextInterface;
use App\NickServ\Application\Port\In\IrcAuthorizationContext;

final readonly class NickServCRegServAuthorizationContextAdapter implements CRegServAuthorizationContextInterface
{
    public function __construct(
        private IrcAuthorizationContext $authorizationContext,
    ) {}

    public function setCurrentUser(string $uid, bool $isIdentified, bool $isOper): void
    {
        $this->authorizationContext->setCurrentUser($uid, $isIdentified, $isOper);
    }

    public function clear(): void
    {
        $this->authorizationContext->clear();
    }
}
