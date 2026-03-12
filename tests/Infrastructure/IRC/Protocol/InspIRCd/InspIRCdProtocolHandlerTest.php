<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\InspIRCd;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdProtocolHandler;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(InspIRCdProtocolHandler::class)]
final class InspIRCdProtocolHandlerTest extends TestCase
{
    private function createHandler(string $sid = 'A0A'): InspIRCdProtocolHandler
    {
        return new InspIRCdProtocolHandler($sid);
    }

    private function createServerLink(): ServerLink
    {
        return new ServerLink(
            serverName: new ServerName('services.test.local'),
            host: new Hostname('127.0.0.1'),
            port: new Port(7029),
            password: new LinkPassword('link-secret'),
            description: 'Ares IRC Services',
            useTls: false,
        );
    }

    #[Test]
    public function getProtocolNameReturnsInspircd(): void
    {
        $handler = $this->createHandler();

        self::assertSame('inspircd', $handler->getProtocolName());
    }

    #[Test]
    public function getSupportedCapabilitiesReturnsEmpty(): void
    {
        $handler = $this->createHandler();

        self::assertSame([], $handler->getSupportedCapabilities());
    }

    #[Test]
    public function parseRawLineDelegatesToIRCMessage(): void
    {
        $handler = $this->createHandler();

        $msg = $handler->parseRawLine(':server PRIVMSG #chan :hello');

        self::assertSame('PRIVMSG', $msg->command);
        self::assertSame('server', $msg->prefix);
        self::assertSame(['#chan'], $msg->params);
        self::assertSame('hello', $msg->trailing);
    }

    #[Test]
    public function formatMessageDelegatesToIRCMessage(): void
    {
        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'PONG', params: ['target']);

        $raw = $handler->formatMessage($msg);

        self::assertSame('PONG target', $raw);
    }

    #[Test]
    public function performHandshakeWritesServerLine(): void
    {
        $lines = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $handler = $this->createHandler('B1B');
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        self::assertCount(1, $lines);
        self::assertSame('SERVER services.test.local link-secret 0 B1B :Ares IRC Services', $lines[0]);
    }

    #[Test]
    public function handleIncomingPingWritesPong(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'PING', trailing: 'token');

        $handler->handleIncoming($msg, $connection);

        self::assertSame(['PONG :token'], $written);
    }

    #[Test]
    public function handleIncomingEndburstWritesEndburst(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('C2C');
        $msg = new IRCMessage(command: 'ENDBURST');

        $handler->handleIncoming($msg, $connection);

        self::assertSame([':C2C ENDBURST'], $written);
    }

    #[Test]
    public function handleIncomingUnknownCommandWritesNothing(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'PRIVMSG', params: ['#chan'], trailing: 'hi');

        $handler->handleIncoming($msg, $connection);
    }
}
