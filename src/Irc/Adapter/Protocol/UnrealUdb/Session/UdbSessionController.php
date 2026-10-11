<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbFrame;
use App\Irc\Adapter\Runtime\SessionEventPump;

/** Lifecycle and inbound-wire boundary consumed by the protocol handler. */
interface UdbSessionController
{
    public function setEventPump(?SessionEventPump $eventPump): void;

    public function setOwnName(string $ownName): void;

    public function onRemoteServer(string $sid, string $serverName): void;

    /** Prepares SQL stream identity before burst callbacks, without sending HEL. */
    public function prepareLocalStream(): void;

    public function onLinkReady(ConnectionInterface $connection): void;

    public function handleFrame(UdbFrame $frame, ConnectionInterface $connection): void;

    /** Native PONG is not nonce-authenticated and cannot complete bootstrap. */
    public function handlePong(?string $sourceSid, ?string $token, ConnectionInterface $connection): void;

    /** A native nonce-echoing 402 is transport ordering only, never ACK credit. */
    public function handleTransportBarrier(?string $source, string $target, string $nonce, ConnectionInterface $connection): void;

    public function tick(?ConnectionInterface $connection = null): void;

    public function reset(): void;
}
