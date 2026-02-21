<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command;

/**
 * Abstracts the IRC send operations needed by NickServ.
 * Implemented by NickServBot in the Infrastructure layer.
 */
interface NickServNotifierInterface
{
    /** Send a NOTICE from NickServ to a target user (by UID or nick). */
    public function sendNotice(string $targetUidOrNick, string $message): void;

    /**
     * Set a user mode on a user (requires ulines in UnrealIRCd).
     * E.g. setMode('001R2OC01', '+r') to mark a user as identified.
     */
    public function setUserMode(string $targetUid, string $modes): void;

    /**
     * Force a user to change their nickname (SVSNICK).
     * Used for nick protection: rename to guest nick on timeout.
     */
    public function forceNick(string $targetUid, string $newNick): void;
}
