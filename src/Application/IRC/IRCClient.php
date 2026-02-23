<?php

declare(strict_types=1);

namespace App\Application\IRC;

use App\Application\Maintenance\MaintenanceScheduler;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Domain\IRC\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrates the lifecycle of an IRC server-to-server link:
 * connect, run the read loop, and disconnect.
 */
class IRCClient
{
    private ?ServerLink $activeLink = null;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly ProtocolHandlerInterface $protocol,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly MaintenanceScheduler $maintenanceScheduler,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function connect(ServerLink $link): void
    {
        $this->logger->info('Initiating S2S link.', [
            'server' => (string) $link->serverName,
            'host' => (string) $link->host,
            'port' => $link->port->value,
            'protocol' => $this->protocol->getProtocolName(),
            'tls' => $link->useTls,
        ]);

        $this->connection->connect();
        $this->protocol->performHandshake($this->connection, $link);
        $this->activeLink = $link;

        $this->eventDispatcher->dispatch(new ConnectionEstablishedEvent($link));
    }

    /**
     * Blocking read loop. Reads lines from the IRCD and dispatches
     * a MessageReceivedEvent for each one. Exits when the connection drops.
     */
    public function run(): void
    {
        $this->logger->info('Entering read loop.', [
            'protocol' => $this->protocol->getProtocolName(),
        ]);

        while ($this->connection->isConnected()) {
            $rawLine = $this->connection->readLine();

            if (null === $rawLine) {
                $this->maintenanceScheduler->tick();
                usleep(10_000);
                continue;
            }

            if ('' === $rawLine) {
                continue;
            }

            $message = $this->protocol->parseRawLine($rawLine);

            $this->protocol->handleIncoming($message, $this->connection);

            $this->eventDispatcher->dispatch(new MessageReceivedEvent($message));
        }

        $this->logger->warning('Read loop terminated: connection closed by remote host.');
    }

    public function disconnect(?string $reason = null): void
    {
        $this->logger->info('Disconnecting.', ['reason' => $reason ?? 'none']);

        if (null !== $this->activeLink) {
            $this->eventDispatcher->dispatch(
                new ConnectionLostEvent($this->activeLink, $reason)
            );
        }

        $this->connection->disconnect();
        $this->activeLink = null;
    }

    public function getProtocolName(): string
    {
        return $this->protocol->getProtocolName();
    }
}
