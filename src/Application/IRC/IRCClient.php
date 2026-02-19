<?php

declare(strict_types=1);

namespace App\Application\IRC;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Domain\IRC\Server\ServerLink;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrates the lifecycle of an IRC server-to-server link:
 * connect, run the read loop, and disconnect.
 */
class IRCClient
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ProtocolHandlerInterface $protocol,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function connect(ServerLink $link): void
    {
        $this->connection->connect();
        $this->protocol->performHandshake($this->connection, $link);
        $this->eventDispatcher->dispatch(new ConnectionEstablishedEvent($link));
    }

    /**
     * Blocking read loop. Reads lines from the IRCD and dispatches
     * a MessageReceivedEvent for each one. Exits when the connection drops.
     */
    public function run(): void
    {
        while ($this->connection->isConnected()) {
            $rawLine = $this->connection->readLine();

            if ($rawLine === null) {
                usleep(10_000);
                continue;
            }

            if ('' === $rawLine) {
                continue;
            }

            $message = $this->protocol->parseRawLine($rawLine);
            $this->eventDispatcher->dispatch(new MessageReceivedEvent($message));
        }
    }

    public function disconnect(?string $reason = null): void
    {
        $link = null;

        if ($link !== null) {
            $this->eventDispatcher->dispatch(new ConnectionLostEvent($link, $reason));
        }

        $this->connection->disconnect();
    }

    public function getProtocolName(): string
    {
        return $this->protocol->getProtocolName();
    }
}
