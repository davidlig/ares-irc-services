<?php

declare(strict_types=1);

namespace App\Domain\IRC\Protocol;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;

interface ProtocolHandlerInterface
{
    /**
     * Performs the server-to-server link handshake sequence.
     */
    public function performHandshake(ConnectionInterface $connection, ServerLink $link): void;

    /**
     * Parses a raw IRC line into an IRCMessage.
     */
    public function parseRawLine(string $rawLine): IRCMessage;

    /**
     * Serializes an IRCMessage back to a raw IRC line.
     */
    public function formatMessage(IRCMessage $message): string;

    /**
     * Returns a unique identifier for this protocol (e.g. 'unreal', 'inspircd').
     */
    public function getProtocolName(): string;

    /**
     * Returns the list of protocol capabilities supported.
     *
     * @return string[]
     */
    public function getSupportedCapabilities(): array;
}
