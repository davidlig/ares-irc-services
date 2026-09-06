<?php

declare(strict_types=1);

namespace App\Irc\Application\Connect;

final readonly class ConnectToServerCommand
{
    public function __construct(
        public string $serverName,
        public string $host,
        public int $port,
        public string $password,
        public string $description,
        public bool $useTls = false,
        public bool $tlsVerifyPeer = true,
    ) {}
}
