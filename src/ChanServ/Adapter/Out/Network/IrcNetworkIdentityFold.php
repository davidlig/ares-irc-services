<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\NetworkIdentityFold;
use App\Irc\Application\Port\In\NetworkIdentityEquivalence;

final readonly class IrcNetworkIdentityFold implements NetworkIdentityFold
{
    public function __construct(private NetworkIdentityEquivalence $network) {}

    public function fold(string $value): string
    {
        return $this->network->fold($value);
    }
}
