<?php

declare(strict_types=1);

namespace App\Tests\Application\IRC\Connect;

use App\Application\IRC\Connect\ConnectToServerCommand;
use App\Application\IRC\Connect\ConnectToServerHandler;
use App\Application\IRC\IRCClient;
use App\Application\IRC\IRCClientFactoryInterface;
use App\Domain\IRC\Server\ServerLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectToServerHandler::class)]
final class ConnectToServerHandlerTest extends TestCase
{
    #[Test]
    public function handleBuildsServerLinkCallsFactoryAndConnectThenReturnsClient(): void
    {
        $command = new ConnectToServerCommand(
            serverName: 'services.test.local',
            host: '127.0.0.1',
            port: 7029,
            password: 'link-secret',
            description: 'Ares Test',
            protocol: 'unreal',
            useTls: true,
        );

        $capturedLink = null;
        $client = $this->createMock(IRCClient::class);
        $client->expects(self::once())->method('connect')->willReturnCallback(static function (ServerLink $link) use (&$capturedLink): void {
            $capturedLink = $link;
        });

        $factory = $this->createMock(IRCClientFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('unreal', self::callback(static function (ServerLink $link) use (&$capturedLink): bool {
            $capturedLink = $link;

            return true;
        }))->willReturn($client);

        $handler = new ConnectToServerHandler($factory);

        $result = $handler->handle($command);

        self::assertSame($client, $result);
        self::assertInstanceOf(ServerLink::class, $capturedLink);
        self::assertNotNull($capturedLink);

        $link = $capturedLink;
        self::assertSame('services.test.local', $link->serverName->value);
        self::assertSame('127.0.0.1', $link->host->value);
        self::assertSame(7029, $link->port->value);
        self::assertSame('link-secret', $link->password->value);
        self::assertSame('Ares Test', $link->description);
        self::assertTrue($link->useTls);
    }

    #[Test]
    public function handleUsesProtocolFromCommandForFactoryCreate(): void
    {
        $command = new ConnectToServerCommand(
            serverName: 's.local',
            host: 'irc.example.com',
            port: 7100,
            password: 'p',
            description: 'Desc',
            protocol: 'inspircd',
            useTls: false,
        );

        $client = $this->createMock(IRCClient::class);
        $client->expects(self::atLeastOnce())->method('connect')->willReturnCallback(static function (): void {});

        $factory = $this->createMock(IRCClientFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('inspircd', self::anything())->willReturn($client);

        $handler = new ConnectToServerHandler($factory);

        $handler->handle($command);
    }
}
