<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\OperServ\Application\Port\Out\RawLineTransport;

final readonly class LegacyRawLineTransport implements RawLineTransport
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
