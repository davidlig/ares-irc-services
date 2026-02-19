<?php

declare(strict_types=1);

namespace App\Application\IRC\Connect;

readonly class ConnectToServerCommand
{
    public function __construct(
        public readonly string $serverName,
        public readonly string $host,
        public readonly int $port,
        public readonly string $password,
        public readonly string $description,
        public readonly string $protocol,
        public readonly bool $useTls = false,
    ) {
    }
}
