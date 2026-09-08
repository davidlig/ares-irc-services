<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface RawLineTransport
{
    public function isConnected(): bool;

    public function send(string $line): void;
}
