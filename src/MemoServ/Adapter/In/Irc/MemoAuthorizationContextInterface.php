<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

interface MemoAuthorizationContextInterface
{
    public function setCurrentUser(string $uid, bool $isIdentified, bool $isOper): void;

    public function clear(): void;
}
