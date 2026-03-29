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

    /**
     * Apply a mode change to a user (e.g. "+r", "-oHq").
     * Updates the local NetworkUser state after sending SVSMODE.
     */
    public function applyModeChange(string $uid, string $modeDelta): void;

    /**
     * Update the virtual host for a user after sending CHGHOST.
     * Updates the local NetworkUser state to keep displayHost in sync.
     */
    public function updateVhost(string $uid, string $vhost): void;
}
