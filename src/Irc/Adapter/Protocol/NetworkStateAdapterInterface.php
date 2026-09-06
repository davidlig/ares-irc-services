<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

/**
 * Port: translates protocol-specific wire messages into domain events.
 * One implementation per IRCd (Unreal, InspIRCd). The router delegates
 * MessageReceivedEvent to the adapter for the active protocol.
 */
interface NetworkStateAdapterInterface
{
    /**
     * Protocol identifier used by the router to select this adapter (e.g. "unreal", "inspircd").
     */
    public function getSupportedProtocol(): string;

    /**
     * Parses the message and dispatches domain events. Does not write to repositories.
     */
    public function handleMessage(IRCMessage $message): void;
}
