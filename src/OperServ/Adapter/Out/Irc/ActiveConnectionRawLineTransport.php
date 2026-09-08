<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\OperServ\Application\Port\Out\RawLineTransport;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;

final readonly class ActiveConnectionRawLineTransport implements RawLineTransport
{
    public function __construct(private ActiveConnectionHolderInterface $connection) {}

    public function isConnected(): bool
    {
        return $this->connection->isConnected();
    }

    public function send(string $line): void
    {
        $this->connection->writeLine($line);
    }
}
