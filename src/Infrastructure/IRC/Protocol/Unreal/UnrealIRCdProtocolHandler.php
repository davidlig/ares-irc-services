<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\Unreal;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;

/**
 * Implements the UnrealIRCd server-to-server link protocol (U4/U6).
 *
 * Handshake sequence:
 *   1. PASS :<password>
 *   2. PROTOCTL <capabilities>
 *   3. SERVER <name> <hopcount> :<description>
 */
class UnrealIRCdProtocolHandler extends AbstractProtocolHandler
{
    private const PROTOCOL_NAME = 'unreal';

    /**
     * PROTOCTL capabilities advertised to UnrealIRCd.
     */
    private const CAPABILITIES = [
        'NOQUIT',
        'NICKv2',
        'SJOIN',
        'SJ3',
        'NICKIP',
        'TKLEXT',
        'ESVID',
        'MLOCK',
        'EXTSWHOIS',
    ];

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
        $connection->writeLine(sprintf('PASS :%s', $link->password));
        $connection->writeLine(sprintf('PROTOCTL %s', implode(' ', self::CAPABILITIES)));
        $connection->writeLine(sprintf('SERVER %s 1 :%s', $link->serverName, $link->description));
    }
}
