<?php

declare(strict_types=1);

namespace App\Application\IRC\Connect;

use App\Application\IRC\IRCClient;
use App\Application\IRC\IRCClientFactory;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;

final readonly class ConnectToServerHandler
{
    public function __construct(
        private readonly IRCClientFactory $clientFactory,
    ) {
    }

    public function handle(ConnectToServerCommand $command): IRCClient
    {
        $serverLink = new ServerLink(
            serverName: new ServerName($command->serverName),
            host: new Hostname($command->host),
            port: new Port($command->port),
            password: new LinkPassword($command->password),
            description: $command->description,
            useTls: $command->useTls,
        );

        $client = $this->clientFactory->create($command->protocol, $serverLink);
        $client->connect($serverLink);

        return $client;
    }
}
