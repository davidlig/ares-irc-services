<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

/**
 * Protocol-neutral view of the active S2S connection.
 */
interface ActiveConnectionHolderInterface
{
    public function getServerSid(): ?string;

    public function writeLine(string $line): void;

    public function isConnected(): bool;
}
