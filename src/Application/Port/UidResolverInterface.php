<?php

declare(strict_types=1);

namespace App\Application\Port;

interface UidResolverInterface
{
    public function resolveUidToNick(string $uid): ?string;
}
