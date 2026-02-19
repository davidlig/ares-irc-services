<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\InspIRCd;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;

/**
 * Implements the InspIRCd SpanTree server-to-server link protocol (v1.2+).
 *
 * Handshake sequence:
 *   1. SERVER <name> <password> <hopcount> <SID> :<description>
 *
 * The SID is a 3-character alphanumeric server identifier unique on the network.
 */
class InspIRCdProtocolHandler extends AbstractProtocolHandler
{
    private const PROTOCOL_NAME = 'inspircd';

    public function __construct(
        private readonly string $sid = 'A0A',
    ) {
    }

    public function getProtocolName(): string
    {
        return self::PROTOCOL_NAME;
    }

    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
    {
        $connection->writeLine(sprintf(
            'SERVER %s %s 0 %s :%s',
            $link->serverName,
            $link->password,
            $this->sid,
            $link->description,
        ));
    }
}
