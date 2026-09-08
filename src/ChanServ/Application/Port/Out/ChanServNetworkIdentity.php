<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface ChanServNetworkIdentity
{
    public function isChanServUid(string $uid): bool;
}
