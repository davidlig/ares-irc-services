<?php

declare(strict_types=1);

namespace App\Domain\IRC\Connection;

interface ConnectionInterface
{
    public function connect(): void;

    public function disconnect(): void;

    public function writeLine(string $data): void;

    public function readLine(): ?string;

    public function isConnected(): bool;

    public function getStatus(): ConnectionStatus;
}
