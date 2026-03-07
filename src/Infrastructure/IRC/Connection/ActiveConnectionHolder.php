<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connection;

use App\Application\Port\ProtocolModuleInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Holds the active S2S connection, our server SID and the active protocol module.
 * Services obtain the module (handler, formatters, actions) from here; each IRCd
 * type is encapsulated in its own module (Unreal, InspIRCd, etc.).
 */
final class ActiveConnectionHolder implements EventSubscriberInterface
{
    private ?ConnectionInterface $connection = null;

    private ?string $serverSid = null;

    private ?ProtocolModuleInterface $protocolModule = null;

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

    public function setProtocolModule(ProtocolModuleInterface $module): void
    {
        $this->protocolModule = $module;
    }

    public function getProtocolModule(): ?ProtocolModuleInterface
    {
        return $this->protocolModule;
    }

    public function getProtocolHandler(): ?ProtocolHandlerInterface
    {
        return $this->protocolModule?->getHandler();
    }
}
