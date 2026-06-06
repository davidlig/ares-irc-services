<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connect;

use App\Application\IRC\Connect\ConnectToServerCommand;
use App\Application\IRC\Connect\ConnectToServerHandlerInterface;
use App\Application\IRC\IrcSessionInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Runtime\IRCClientFactoryInterface;

final readonly class ConnectToServerHandler implements ConnectToServerHandlerInterface
{
    public function __construct(
        private readonly IRCClientFactoryInterface $clientFactory,
    ) {
    }

    public function handle(ConnectToServerCommand $command): IrcSessionInterface
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
