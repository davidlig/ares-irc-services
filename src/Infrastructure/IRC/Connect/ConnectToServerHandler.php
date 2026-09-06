<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Connect;

use App\Infrastructure\IRC\Runtime\IRCClientFactoryInterface;
use App\Irc\Application\Connect\ConnectToServerCommand;
use App\Irc\Application\Connect\ConnectToServerHandlerInterface;
use App\Irc\Application\IrcSessionInterface;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;

final readonly class ConnectToServerHandler implements ConnectToServerHandlerInterface
{
    public function __construct(
        private IRCClientFactoryInterface $clientFactory,
    ) {}

    public function handle(ConnectToServerCommand $command): IrcSessionInterface
    {
        $serverLink = new ServerLink(
            serverName: new ServerName($command->serverName),
            host: new Hostname($command->host),
            port: new Port($command->port),
            password: new LinkPassword($command->password),
            description: $command->description,
            useTls: $command->useTls,
            tlsVerifyPeer: $command->tlsVerifyPeer,
        );

        $client = $this->clientFactory->create($command->protocol, $serverLink);
        $client->connect($serverLink);

        return $client;
    }
}
