<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\Unreal;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Protocol\Unreal\UnrealIRCdProtocolHandler;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(UnrealIRCdProtocolHandler::class)]
final class UnrealIRCdProtocolHandlerTest extends TestCase
{
    private function createHandler(string $sid = '001'): UnrealIRCdProtocolHandler
    {
        return new UnrealIRCdProtocolHandler($sid);
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
    public function getProtocolNameReturnsUnreal(): void
    {
        $handler = $this->createHandler();

        self::assertSame('unreal', $handler->getProtocolName());
    }

    #[Test]
    public function getSupportedCapabilitiesReturnsNonEmptyList(): void
    {
        $handler = $this->createHandler();

        $caps = $handler->getSupportedCapabilities();

        self::assertNotEmpty($caps);
        self::assertContains('NOQUIT', $caps);
        self::assertContains('SJOIN', $caps);
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
    public function performHandshakeWritesPassProtoctlServerInOrder(): void
    {
        $lines = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $handler = $this->createHandler('002');
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        self::assertSame('PASS :link-secret', $lines[0]);
        self::assertStringContainsString('PROTOCTL EAUTH=services.test.local SID=002', $lines[1]);
        self::assertStringStartsWith('PROTOCTL ', $lines[2]);
        self::assertSame('SERVER services.test.local 1 :Ares IRC Services', $lines[3]);
        self::assertCount(4, $lines);
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
        $msg = new IRCMessage(command: 'PING', trailing: 'token123');

        $handler->handleIncoming($msg, $connection);

        self::assertSame(['PONG :token123'], $written);
    }

    #[Test]
    public function handleIncomingEosWritesEosAndDispatchesBurstComplete(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('005');
        $msg = new IRCMessage(command: 'EOS');

        $handler->handleIncoming($msg, $connection);

        self::assertSame(':005 EOS', $written[0]);
        self::assertCount(1, $written);
    }

    #[Test]
    public function handleIncomingNetinfoWritesNetinfoLine(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'NETINFO', trailing: 'My Network');

        $handler->handleIncoming($msg, $connection);

        self::assertCount(1, $written);
        self::assertMatchesRegularExpression('/^NETINFO 0 \d+ 6100 \* 0 0 0 :My Network$/', $written[0]);
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
