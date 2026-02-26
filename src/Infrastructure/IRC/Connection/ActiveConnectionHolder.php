<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connection;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Holds the active S2S connection and our server SID from NetworkBurstCompleteEvent.
 * Services use it to write lines and to obtain the current server SID (protocol-agnostic).
 */
class ActiveConnectionHolder implements EventSubscriberInterface
{
    private ?ConnectionInterface $connection = null;

    private ?string $serverSid = null;

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 0],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->connection = $event->connection;
        $this->serverSid = $event->serverSid;
    }

    public function getConnection(): ?ConnectionInterface
    {
        return $this->connection;
    }

    public function getServerSid(): ?string
    {
        return $this->serverSid;
    }

    public function writeLine(string $line): void
    {
        $this->connection?->writeLine($line);
    }

    public function isConnected(): bool
    {
        return null !== $this->connection;
    }
}
