<?php

declare(strict_types=1);

namespace App\Domain\IRC\Server;

use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;

/**
 * Represents the configuration for a server-to-server link between
 * the services daemon and an IRC daemon.
 */
readonly class ServerLink
{
    public function __construct(
        public ServerName $serverName,
        public Hostname $host,
        public Port $port,
        public LinkPassword $password,
        public string $description,
        public bool $useTls = false,
        public bool $tlsVerifyPeer = true,
    ) {}
}
