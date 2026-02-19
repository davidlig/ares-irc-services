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
        public readonly ServerName $serverName,
        public readonly Hostname $host,
        public readonly Port $port,
        public readonly LinkPassword $password,
        public readonly string $description,
        public readonly bool $useTls = false,
    ) {
    }
}
