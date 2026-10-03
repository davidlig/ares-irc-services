<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

interface ConnectionInterface
{
    public function connect(): void;

    public function disconnect(): void;

    /**
     * Writes one IRC line or a list of complete lines synchronously, adding CRLF
     * to each line. A string is written immediately; an empty list is a no-op.
     *
     * @param string|list<string> $data
     */
    public function writeLine(array|string $data): void;

    public function readLine(): ?string;

    public function isConnected(): bool;

    public function getStatus(): ConnectionStatus;
}
