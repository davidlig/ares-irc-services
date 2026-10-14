<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc;

interface CRegServAuthorizationContextInterface
{
    public function setCurrentUser(string $uid, bool $isIdentified, bool $isOper): void;

    public function clear(): void;
}
