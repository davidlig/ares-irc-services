<?php

declare(strict_types=1);

namespace App\Application\IRC\Connect;

final readonly class ConnectToServerCommand
{
    public function __construct(
        public string $serverName,
        public string $host,
        public int $port,
        public string $password,
        public string $description,
        public string $protocol,
        public bool $useTls = false,
        public bool $tlsVerifyPeer = true,
    ) {}
}
