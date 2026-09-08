<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChanServNetworkIdentity;
use App\Shared\Application\ServiceUidRegistry;

final readonly class ServiceRegistryChanServNetworkIdentity implements ChanServNetworkIdentity
{
    public function __construct(private ServiceUidRegistry $uidRegistry) {}

    public function isChanServUid(string $uid): bool
    {
        return $this->uidRegistry->getUid('chanserv') === $uid;
    }
}
