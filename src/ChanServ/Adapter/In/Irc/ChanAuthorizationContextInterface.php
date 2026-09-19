<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

interface ChanAuthorizationContextInterface
{
    public function setCurrentUser(string $uid, bool $isIdentified, bool $isOper): void;

    public function clear(): void;
}
