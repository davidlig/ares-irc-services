<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented by Core: resolve a connected user by UID or nick for Services.
 *
 * Services use this to obtain SenderView (never Domain\IRC entities).
 */
interface NetworkUserLookupPort
{
    public function findByUid(string $uid): ?SenderView;

    public function findByNick(string $nick): ?SenderView;

    /** @return string[] UIDs of all users currently on the network (for maintenance/pruning). */
    public function listConnectedUids(): array;
}
