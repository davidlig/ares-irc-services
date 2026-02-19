<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;

/**
 * Implements the UnrealIRCd 4.x / 5.x / 6.x server-to-server link protocol.
 *
 * Handshake sequence (per https://www.unrealircd.org/docs/Server_protocol:Introduction):
 *   1. PASS :<password>
 *   2. PROTOCTL EAUTH=<server_name> SID=<sid>   ← identifies the new protocol; without this
 *                                                   UnrealIRCd treats the link as 3.2.x and rejects it
 *   3. PROTOCTL <capabilities>
 *   4. SERVER <name> 1 :<description>
 *
 * The SID must be a unique 3-digit numeric assigned to this server on the network.
 * See https://www.unrealircd.org/docs/Server_protocol:Server_ID
 */
class UnrealIRCdProtocolHandler extends AbstractProtocolHandler
{
    private const PROTOCOL_NAME = 'unreal';

    /**
     * PROTOCTL capability tokens supported by Ares.
     * Reference: https://www.unrealircd.org/docs/Server_protocol:PROTOCTL_command
     */
    private const CAPABILITIES = [
        'NOQUIT',    // Suppress QUIT for each user on netsplit
        'NICKv2',    // Extended NICK command
        'SJOIN',     // Channel sync via SJOIN
        'SJ3',       // SJOIN extension
        'CLK',       // Clock / timestamp support  ← required in 4.x+
        'TKLEXT',    // Extended TKL (ban) support
        'TKLEXT2',   // Extended TKL v2            ← required in 4.x+
        'NICKIP',    // Include IP in NICK
        'ESVID',     // Extended SVS commands
        'MLOCK',     // Channel mode-lock
        'EXTSWHOIS', // Extended /WHOIS lines
    ];

    public function __construct(
        private readonly string $sid = '001',
    ) {
    }

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function getSupportedCapabilities(): array
    {
        return self::CAPABILITIES;
    }

    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        // Step 1 — link password
        $connection->writeLine(sprintf('PASS :%s', $link->password));

        // Step 2 — EAUTH + SID: this is what tells UnrealIRCd we speak the 4.x+ protocol.
        //          Sending PROTOCTL without EAUTH causes the LINK_OLD_PROTOCOL rejection.
        $connection->writeLine(sprintf('PROTOCTL EAUTH=%s SID=%s', $link->serverName, $this->sid));

        // Step 3 — advertise supported capabilities
        $connection->writeLine(sprintf('PROTOCTL %s', implode(' ', self::CAPABILITIES)));

        // Step 4 — introduce our server
        $connection->writeLine(sprintf('SERVER %s 1 :%s', $link->serverName, $link->description));
    }
}
