<?php

declare(strict_types=1);

namespace App\Tests\Application\IRC\Connect;

use App\Application\IRC\Connect\ConnectToServerCommand;
use App\Application\IRC\Connect\ConnectToServerHandler;
use App\Application\IRC\IRCClient;
use App\Application\IRC\IRCClientFactoryInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectToServerHandler::class)]
final class ConnectToServerHandlerTest extends TestCase
{
    #[Test]
    public function handle_builds_server_link_calls_factory_and_connect_then_returns_client(): void
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
        $client->expects(self::once())->method('connect')->willReturnCallback(function (ServerLink $link) use (&$capturedLink): void {
            $capturedLink = $link;
        });

        $factory = $this->createMock(IRCClientFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('unreal', self::callback(function (ServerLink $link) use (&$capturedLink): bool {
            $capturedLink = $link;

            return true;
        }))->willReturn($client);

        $handler = new ConnectToServerHandler($factory);

        $result = $handler->handle($command);

        self::assertSame($client, $result);
        self::assertInstanceOf(ServerLink::class, $capturedLink);
        self::assertEquals(new ServerName('services.test.local'), $capturedLink->serverName);
        self::assertEquals(new Hostname('127.0.0.1'), $capturedLink->host);
        self::assertEquals(new Port(7029), $capturedLink->port);
        self::assertEquals(new LinkPassword('link-secret'), $capturedLink->password);
        self::assertSame('Ares Test', $capturedLink->description);
        self::assertTrue($capturedLink->useTls);
    }

    #[Test]
    public function handle_uses_protocol_from_command_for_factory_create(): void
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
        $client->method('connect')->willReturnCallback(static function (): void {});

        $factory = $this->createMock(IRCClientFactoryInterface::class);
        $factory->expects(self::once())->method('create')->with('inspircd', self::anything())->willReturn($client);

        $handler = new ConnectToServerHandler($factory);

        $handler->handle($command);
    }
}
