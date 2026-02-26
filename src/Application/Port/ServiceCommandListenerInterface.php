<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Implemented by bots: called by Service Command Gateway when a PRIVMSG
 * targets this service. Services never subscribe to MessageReceivedEvent directly.
 */
interface ServiceCommandListenerInterface
{
    /** Service nick (e.g. "NickServ") — PRIVMSG target is matched case-insensitively. */
    public function getServiceName(): string;

    /** Service UID for UID-based targeting; null to match by nick only. */
    public function getServiceUid(): ?string;

    /** Invoked with sender UID and full message text when a command is received. */
    public function onCommand(string $senderUid, string $text): void;
}
