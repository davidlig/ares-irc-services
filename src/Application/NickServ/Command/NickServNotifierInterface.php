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
     * Send a NOTICE or PRIVMSG from NickServ to a target user (by UID or nick).
     *
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void;

    /**
     * Set the registered (+r) status for a user (identified to the given account).
     * To log out a user pass '0' (zero string) as $accountName.
     */
    public function setUserAccount(string $targetUid, string $accountName): void;

    /**
     * Set raw user modes on a user.
     * Use setUserAccount() for the +r (registered/identified) status.
     */
    public function setUserMode(string $targetUid, string $modes): void;

    /**
     * Force a user to change their nickname.
     * Used for nick protection: rename to guest nick on timeout.
     */
    public function forceNick(string $targetUid, string $newNick): void;

    /**
     * Disconnect a user from the network.
     * Used to free a registered nick held by a ghost or usurper session
     * so the rightful owner can reclaim it via IDENTIFY.
     */
    public function killUser(string $targetUid, string $reason): void;

    /**
     * Set or clear a user's virtual host (hostname visible to others).
     * When $vhost is empty string, the implementation must clear the vhost.
     */
    public function setUserVhost(string $targetUid, string $vhost, string $sourceServerSid): void;
}
