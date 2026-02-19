<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connection;

use App\Domain\IRC\Connection\ConnectionFactoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SocketConnectionFactory implements ConnectionFactoryInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function create(ServerLink $link): ConnectionInterface
    {
        return new SocketConnection(
            host: (string) $link->host,
            port: $link->port->value,
            useTls: $link->useTls,
            timeoutSeconds: $this->timeoutSeconds,
            logger: $this->logger,
        );
    }
}
