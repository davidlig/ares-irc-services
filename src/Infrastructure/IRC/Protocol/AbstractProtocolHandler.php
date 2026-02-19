<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol;

use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;

/**
 * Provides default RFC 1459 parsing/formatting shared by all protocol handlers.
 * Concrete handlers only need to implement performHandshake() and getProtocolName().
 */
abstract class AbstractProtocolHandler implements ProtocolHandlerInterface
{
    public function parseRawLine(string $rawLine): IRCMessage
    {
        return IRCMessage::fromRawLine($rawLine);
    }

    public function formatMessage(IRCMessage $message): string
    {
        return $message->toRawLine();
    }

    public function getSupportedCapabilities(): array
    {
        return [];
    }
}
