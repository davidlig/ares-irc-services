<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\Connect;

use App\Irc\Application\Connect\ConnectToServerCommand;
use App\Irc\Application\Connect\ConnectToServerHandler;
use App\Irc\Application\IrcSessionInterface;
use App\Irc\Application\Port\Out\IrcSessionConnectorInterface;
use App\Irc\Domain\Server\ServerLink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectToServerHandler::class)]
final class ConnectToServerHandlerTest extends TestCase
{
    #[Test]
    public function handleBuildsServerLinkCallsConnectorAndReturnsSession(): void
    {
        $command = new ConnectToServerCommand(
            serverName: 'services.test.local',
            host: '127.0.0.1',
            port: 7029,
            password: 'link-secret',
            description: 'Ares Test',
            useTls: true,
        );

        $capturedLink = null;
        $session = $this->createStub(IrcSessionInterface::class);
        $connector = $this->createMock(IrcSessionConnectorInterface::class);
        $connector->expects(self::once())->method('connect')->with(self::callback(static function (ServerLink $link) use (&$capturedLink): bool {
            $capturedLink = $link;

            return true;
        }))->willReturn($session);

        $handler = new ConnectToServerHandler($connector);

        $result = $handler->handle($command);

        self::assertSame($session, $result);
        self::assertInstanceOf(ServerLink::class, $capturedLink);

        $link = $capturedLink;
        self::assertSame('services.test.local', $link->serverName->value);
        self::assertSame('127.0.0.1', $link->host->value);
        self::assertSame(7029, $link->port->value);
        self::assertSame('link-secret', $link->password->value);
        self::assertSame('Ares Test', $link->description);
        self::assertTrue($link->useTls);
        self::assertTrue($link->tlsVerifyPeer);
    }

    #[Test]
    public function handleBuildsServerLinkWithTlsVerifyPeerDisabled(): void
    {
        $command = new ConnectToServerCommand(
            serverName: 'services.test.local',
            host: '127.0.0.1',
            port: 7029,
            password: 'link-secret',
            description: 'Ares Test',
            useTls: true,
            tlsVerifyPeer: false,
        );

        $capturedLink = null;
        $session = $this->createStub(IrcSessionInterface::class);
        $connector = $this->createMock(IrcSessionConnectorInterface::class);
        $connector->expects(self::once())->method('connect')->with(self::callback(static function (ServerLink $link) use (&$capturedLink): bool {
            $capturedLink = $link;

            return true;
        }))->willReturn($session);

        $handler = new ConnectToServerHandler($connector);
        $result = $handler->handle($command);

        self::assertSame($session, $result);
        self::assertInstanceOf(ServerLink::class, $capturedLink);
        self::assertTrue($capturedLink->useTls);
        self::assertFalse($capturedLink->tlsVerifyPeer);
    }
}
