<?php

declare(strict_types=1);

namespace App\Irc\Domain\Server;

use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;

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
