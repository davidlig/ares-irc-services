<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Holds the active S2S connection, our server SID and the active protocol module.
 * Services obtain the module (handler, formatters, actions) from here; each IRCd
 * type is encapsulated in its own module (Unreal, InspIRCd, etc.).
 */
final class ActiveConnectionHolder implements ActiveProtocolModuleHolderInterface, EventSubscriberInterface
{
    private ?ConnectionInterface $connection = null;

    private ?string $serverSid = null;

    private ?string $remoteServerSid = null;

    private ?ProtocolModuleInterface $protocolModule = null;

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 250],
            ConnectionLostEvent::class => ['onConnectionLost', 0],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->connection = $event->connection;
        $this->serverSid = $event->serverSid;
    }

    public function onConnectionLost(ConnectionLostEvent $event): void
    {
        $this->connection = null;
        $this->serverSid = null;
        $this->remoteServerSid = null;
    }

    public function getConnection(): ?ConnectionInterface
    {
        return $this->connection;
    }

    public function getServerSid(): ?string
    {
        return $this->serverSid;
    }

    public function setRemoteServerSid(string $sid): void
    {
        $this->remoteServerSid = $sid;
    }

    public function getRemoteServerSid(): ?string
    {
        return $this->remoteServerSid;
    }

    public function writeLine(string $line): void
    {
        $this->connection?->writeLine($line);
    }

    public function isConnected(): bool
    {
        return null !== $this->connection && $this->connection->isConnected();
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
        if (!$this->protocolModule instanceof ProtocolRuntimeModuleInterface) {
            return null;
        }

        return $this->protocolModule->getHandler();
    }
}
