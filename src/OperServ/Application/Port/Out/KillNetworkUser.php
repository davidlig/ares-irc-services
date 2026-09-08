<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

/** Performs the network-side disconnect after the application has authorised it. */
interface KillNetworkUser
{
    /** Returns false when no active network protocol can perform the action. */
    public function kill(string $targetUid, string $reason): bool;
}
