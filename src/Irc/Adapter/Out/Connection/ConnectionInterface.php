<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

interface ConnectionInterface
{
    public function connect(): void;

    public function disconnect(): void;

    public function writeLine(string $data): void;

    /**
     * Writes complete IRC lines synchronously, adding CRLF to each line.
     * An empty batch is a no-op.
     *
     * @param list<string> $lines
     */
    public function writeLines(array $lines): void;

    public function readLine(): ?string;

    public function isConnected(): bool;

    public function getStatus(): ConnectionStatus;
}
