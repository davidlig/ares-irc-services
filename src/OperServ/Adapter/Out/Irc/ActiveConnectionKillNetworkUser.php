<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\OperServ\Application\Port\Out\KillNetworkUser;

/** Performs the protocol-specific KILL only when an active protocol connection exists. */
final readonly class ActiveConnectionKillNetworkUser implements KillNetworkUser
{
    public function __construct(private ActiveConnectionHolderInterface $connection) {}

    public function kill(string $targetUid, string $reason): bool
    {
        $module = $this->connection->getProtocolModule();
        $serverSid = $this->connection->getServerSid();
        if (null === $module || null === $serverSid) {
            return false;
        }

        $module->getServiceActions()->killUser($serverSid, $targetUid, $reason);

        return true;
    }
}
