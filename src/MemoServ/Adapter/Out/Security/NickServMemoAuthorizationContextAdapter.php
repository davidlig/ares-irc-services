<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\Security;

use App\MemoServ\Adapter\In\Irc\MemoAuthorizationContextInterface;
use App\NickServ\Application\Port\In\IrcAuthorizationContext;

final readonly class NickServMemoAuthorizationContextAdapter implements MemoAuthorizationContextInterface
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
