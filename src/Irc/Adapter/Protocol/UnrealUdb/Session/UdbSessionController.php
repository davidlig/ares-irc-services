<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use App\Infrastructure\IRC\Runtime\SessionEventPump;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;

/** Lifecycle and inbound-wire boundary consumed by the protocol handler. */
interface UdbSessionController
{
    public function setEventPump(?SessionEventPump $eventPump): void;

    public function setOwnName(string $ownName): void;

    public function onRemoteServer(string $sid, string $serverName): void;

    public function onLinkReady(ConnectionInterface $connection): void;

    public function handleFrame(UdbFrame $frame, ConnectionInterface $connection): void;

    public function tick(?ConnectionInterface $connection = null): void;

    public function reset(): void;
}
