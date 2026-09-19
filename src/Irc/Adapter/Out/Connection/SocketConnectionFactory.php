<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

use App\Irc\Domain\Server\ServerLink;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class SocketConnectionFactory implements ConnectionFactoryInterface
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function create(ServerLink $link): ConnectionInterface
    {
        return new SocketConnection(
            host: (string) $link->host,
            port: $link->port->value,
            useTls: $link->useTls,
            tlsVerifyPeer: $link->tlsVerifyPeer,
            timeoutSeconds: $this->timeoutSeconds,
            logger: $this->logger,
        );
    }
}
