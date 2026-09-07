<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

/**
 * Public boundary for IRC service adapters that establish the current actor.
 */
interface IrcAuthorizationContext
{
    public function setCurrentUser(string $uid, bool $isIdentified, bool $isOper): void;

    public function clear(): void;
}
