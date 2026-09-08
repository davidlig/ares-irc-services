<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

interface UidResolverInterface
{
    public function resolveUidToNick(string $uid): ?string;
}
