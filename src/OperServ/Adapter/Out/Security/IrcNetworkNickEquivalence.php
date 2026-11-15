<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Security;

use App\Irc\Application\Port\In\NetworkIdentityEquivalence;
use App\OperServ\Application\Port\Out\NetworkNickEquivalence;

final readonly class IrcNetworkNickEquivalence implements NetworkNickEquivalence
{
    public function __construct(private NetworkIdentityEquivalence $network) {}

    public function equivalent(string $left, string $right): bool
    {
        return $this->network->equivalent($left, $right);
    }
}
