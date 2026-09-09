<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

interface UidResolverInterface
{
    public function resolveUidToNick(string $uid): ?string;
}
