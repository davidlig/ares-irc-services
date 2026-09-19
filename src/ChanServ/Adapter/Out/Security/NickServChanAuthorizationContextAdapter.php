<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Security;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationContextInterface;
use App\NickServ\Application\Port\In\IrcAuthorizationContext;

final readonly class NickServChanAuthorizationContextAdapter implements ChanAuthorizationContextInterface
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
