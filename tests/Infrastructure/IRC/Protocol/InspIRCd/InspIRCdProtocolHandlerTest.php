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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

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

    private function createRecordingConnection(array &$written): ConnectionInterface
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        return $connection;
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
    public function parseRawLineStripsIrcv3Tags(): void
    {
        $handler = $this->createHandler();

        $msg = $handler->parseRawLine('@time=2026-04-18T21:23:31.529Z;msgid=994~1 :994AAAAAA PRIVMSG 0A0AAAAAA :help');

        self::assertSame('PRIVMSG', $msg->command);
        self::assertSame('994AAAAAA', $msg->prefix);
        self::assertSame(['0A0AAAAAA'], $msg->params);
        self::assertSame('help', $msg->trailing);
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
    public function performHandshakeSendsMinimalCapabAndServerLine(): void
    {
        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler = $this->createHandler('B1B');
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        self::assertSame('CAPAB START 1206', $written[0]);
        self::assertSame('CAPAB CAPABILITIES :CASEMAPPING=ascii', $written[1]);
        self::assertSame('CAPAB END', $written[2]);
        self::assertSame('SERVER services.test.local link-secret B1B :Ares IRC Services', $written[3]);
        self::assertCount(4, $written);
    }

    #[Test]
    public function performHandshakeDoesNotSendModulesOrModes(): void
    {
        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler = $this->createHandler();
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        foreach ($written as $line) {
            self::assertStringNotContainsString('CAPAB MODULES', $line);
            self::assertStringNotContainsString('CAPAB MODSUPPORT', $line);
            self::assertStringNotContainsString('CAPAB CHANMODES', $line);
            self::assertStringNotContainsString('CAPAB USERMODES', $line);
            self::assertStringNotContainsString('CAPAB EXTBANS', $line);
        }
    }

    #[Test]
    public function performHandshakeServerLineHasNoHopCountForV4(): void
    {
        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler = $this->createHandler('0A0');
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        $serverLine = $written[3];
        self::assertSame('SERVER services.test.local link-secret 0A0 :Ares IRC Services', $serverLine);
        self::assertStringNotContainsString(' 0 0A0 ', $serverLine);
    }

    #[Test]
    public function capabCapabilitiesContainsCaseMappingWithoutChallenge(): void
    {
        $written = [];
        $connection = $this->createRecordingConnection($written);

        $handler = $this->createHandler();
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        $capsLine = $written[1];
        self::assertStringContainsString('CASEMAPPING=ascii', $capsLine);
        self::assertStringNotContainsString('CHALLENGE', $capsLine);
    }

    #[Test]
    public function handleIncomingPingRespondsWithPongWithSidPrefix(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('0A0');
        $msg = new IRCMessage(command: 'PING', prefix: '994', params: ['0A0']);

        $handler->handleIncoming($msg, $connection);

        self::assertSame([':0A0 PONG 994'], $written);
    }

    #[Test]
    public function handleIncomingPingWithoutPrefixUsesParam(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('0A0');
        $msg = new IRCMessage(command: 'PING', params: ['994']);

        $handler->handleIncoming($msg, $connection);

        self::assertSame([':0A0 PONG 994'], $written);
    }

    #[Test]
    public function handleIncomingErrorLogsCritical(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'ERROR', trailing: 'Ping timeout');

        $handler->handleIncoming($msg, $connection);
    }

    #[Test]
    public function handleIncomingEndburstDispatchesSyncCompleteAndSendsBurstIfNotSent(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('C2C');
        $msg = new IRCMessage(command: 'ENDBURST');

        $handler->handleIncoming($msg, $connection);

        self::assertCount(2, $written);
        self::assertMatchesRegularExpression('/^:C2C BURST \d+$/', $written[0]);
        self::assertSame(':C2C ENDBURST', $written[1]);
    }

    #[Test]
    public function handleIncomingEndburstDoesNotResendBurstIfAlreadySent(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('C2C');

        $serverMsg = new IRCMessage(command: 'SERVER', params: ['irc.test.net', 'pass', '994'], trailing: 'Test Server');
        $handler->handleIncoming($serverMsg, $connection);

        $burstCountBefore = count(array_filter($written, static fn (string $line): bool => str_contains($line, ' BURST ')));
        self::assertSame(1, $burstCountBefore);

        $endburstMsg = new IRCMessage(command: 'ENDBURST', prefix: '994');
        $handler->handleIncoming($endburstMsg, $connection);

        $burstCountAfter = count(array_filter($written, static fn (string $line): bool => str_contains($line, ' BURST ')));
        self::assertSame(1, $burstCountAfter);
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
