<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol;

use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Provides RFC 1459 parsing/formatting and universal protocol commands
 * (PING → PONG) shared by all protocol handlers.
 *
 * Subclasses override handleIncoming() to add protocol-specific responses
 * (e.g. EOS for UnrealIRCd, ENDBURST for InspIRCd) and must call parent
 * to preserve the base behaviour.
 */
abstract class AbstractProtocolHandler implements ProtocolHandlerInterface
{
    public function __construct(
        protected readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

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

    /**
     * Handles mandatory RFC 1459 protocol commands.
     * PING must always be answered with PONG to keep the link alive.
     */
    public function handleIncoming(IRCMessage $message, ConnectionInterface $connection): void
    {
        if ('PING' === $message->command) {
            $target = $message->trailing ?? ($message->params[0] ?? '');
            $pong   = 'PONG :' . $target;

            $connection->writeLine($pong);
            $this->logger->debug('> ' . $pong);
        }
    }
}
