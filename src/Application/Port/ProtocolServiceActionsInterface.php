<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port implemented per IRCd protocol: send service-side commands (account, mode,
 * forcenick, kill) in the wire format of the active protocol. The bot delegates
 * here so that Unreal, InspIRCd and future protocols (e.g. P10) can use their
 * own commands (SVS2MODE, AC, etc.).
 */
interface ProtocolServiceActionsInterface
{
    public function setUserAccount(string $serverSid, string $targetUid, string $accountName): void;

    public function setUserMode(string $serverSid, string $targetUid, string $modes): void;

    public function forceNick(string $serverSid, string $targetUid, string $newNick): void;

    public function killUser(string $serverSid, string $targetUid, string $reason): void;
}
