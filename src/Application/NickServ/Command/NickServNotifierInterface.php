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
     * Authenticate a user against an account via SVSLOGIN (UnrealIRCd 6+).
     * This is the correct way to set the "registered" (+r) status and associate
     * the account name shown in /WHOIS. SVSMODE +r alone is ignored in UnrealIRCd 6.
     *
     * To log out a user pass '0' (zero string) as $accountName.
     */
    public function setUserAccount(string $targetUid, string $accountName): void;

    /**
     * Set a raw user mode on a user via SVSMODE (requires ulines in UnrealIRCd).
     * Use setUserAccount() for the +r (registered/identified) status.
     */
    public function setUserMode(string $targetUid, string $modes): void;

    /**
     * Force a user to change their nickname (SVSNICK).
     * Used for nick protection: rename to guest nick on timeout.
     */
    public function forceNick(string $targetUid, string $newNick): void;
}
