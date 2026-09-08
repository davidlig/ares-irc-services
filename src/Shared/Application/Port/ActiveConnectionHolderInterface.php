<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

use App\Irc\Application\Port\In\ProtocolModuleInterface;

/**
 * Port for accessing the active S2S connection and protocol module.
 * Application layer uses this interface to obtain connection state and protocol details
 * without depending on Infrastructure implementation.
 */
interface ActiveConnectionHolderInterface
{
    public function getServerSid(): ?string;

    public function writeLine(string $line): void;

    public function isConnected(): bool;

    public function setProtocolModule(ProtocolModuleInterface $module): void;

    public function getProtocolModule(): ?ProtocolModuleInterface;
}
