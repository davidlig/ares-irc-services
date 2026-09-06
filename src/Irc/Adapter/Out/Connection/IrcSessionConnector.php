<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Out\Connection;

use App\Irc\Adapter\Runtime\IRCClientFactoryInterface;
use App\Irc\Application\IrcSessionInterface;
use App\Irc\Application\Port\Out\IrcSessionConnectorInterface;
use App\Irc\Domain\Server\ServerLink;

final readonly class IrcSessionConnector implements IrcSessionConnectorInterface
{
    public function __construct(
        private IRCClientFactoryInterface $clientFactory,
    ) {}

    public function connect(string $protocolName, ServerLink $serverLink): IrcSessionInterface
    {
        $client = $this->clientFactory->create($protocolName, $serverLink);
        $client->connect($serverLink);

        return $client;
    }
}
