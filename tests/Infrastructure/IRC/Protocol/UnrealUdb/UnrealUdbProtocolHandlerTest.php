<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Domain\Udb\Repository\UdbBlockStateRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionCoordinator;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSnapshotProviderInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(UnrealUdbProtocolHandler::class)]
final class UnrealUdbProtocolHandlerTest extends TestCase
{
    use CreatesUdbRecordWriter;

    /** @var list<string> */
    private array $written = [];

    private function createConnection(): ConnectionInterface
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        return $connection;
    }

    private function createHandler(string $sid = '002'): UnrealUdbProtocolHandler
    {
        $coordinator = new UdbSessionCoordinator(
            $sid,
            $this->createStub(UdbBlockStateRepositoryInterface::class),
            $this->createStub(UdbSnapshotProviderInterface::class),
        );

        return new UnrealUdbProtocolHandler($sid, $coordinator);
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
    public function getProtocolNameReturnsUnrealUdb(): void
    {
        self::assertSame('unrealudb', $this->createHandler()->getProtocolName());
    }

    #[Test]
    public function getSupportedCapabilitiesReturnsNonEmptyList(): void
    {
        $caps = $this->createHandler()->getSupportedCapabilities();

        self::assertNotEmpty($caps);
        self::assertContains('NOQUIT', $caps);
        self::assertContains('SJOIN', $caps);
    }

    #[Test]
    public function parseRawLineDelegatesToIRCMessage(): void
    {
        $msg = $this->createHandler()->parseRawLine(':server PRIVMSG #chan :hello');

        self::assertSame('PRIVMSG', $msg->command);
        self::assertSame('server', $msg->prefix);
        self::assertSame(['#chan'], $msg->params);
        self::assertSame('hello', $msg->trailing);
    }

    #[Test]
    public function formatMessageDelegatesToIRCMessage(): void
    {
        $raw = $this->createHandler()->formatMessage(new IRCMessage(command: 'PONG', params: ['target']));

        self::assertSame('PONG target', $raw);
    }

    #[Test]
    public function performHandshakeWritesPassProtoctlServerInOrder(): void
    {
        $this->written = [];
        $connection = $this->createConnection();
        $handler = $this->createHandler('002');
        $link = $this->createServerLink();

        $handler->performHandshake($connection, $link);

        self::assertSame('PASS :link-secret', $this->written[0]);
        self::assertStringContainsString('PROTOCTL EAUTH=services.test.local SID=002', $this->written[1]);
        self::assertStringStartsWith('PROTOCTL ', $this->written[2]);
        self::assertSame('SERVER services.test.local 1 :Ares IRC Services', $this->written[3]);
        self::assertCount(4, $this->written);
    }

    #[Test]
    public function eosTriggersBurstCompletionOurEosAndHelNegotiation(): void
    {
        $this->written = [];
        $handler = $this->createHandler();
        $connection = $this->createConnection();

        // The handshake captures our own FQDN (onLinkEstablished).
        $handler->performHandshake($connection, $this->createServerLink());
        // Remote identity is captured from the SERVER line before EOS.
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', prefix: '001', params: ['ircd.example.net', '1'], trailing: 'IRCd'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '001'), $connection);

        self::assertContains(':002 EOS', $this->written);
        self::assertContains(':002 DB 001 HEL 4 services.test.local', $this->written);
    }

    #[Test]
    public function serverLinesWithoutIdentityAreIgnored(): void
    {
        $handler = $this->createHandler();

        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['', '1']), $this->createConnection());
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', prefix: '001', params: ['ircd.example.net', '1'], trailing: 'IRCd'), $this->createConnection());

        self::assertTrue(true);
    }

    #[Test]
    public function unprefixedServerLineWithProtoctlSidTriggersHelNegotiation(): void
    {
        $this->written = [];
        $handler = $this->createHandler();
        $connection = $this->createConnection();

        // Real link order: our handshake (captures our FQDN), then the IRCd's
        // UNPREFIXED SERVER introduction, PROTOCTL SID=, and finally EOS.
        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['NOQUIT', 'NICKv2', 'SID=001']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['irc.davidlig.net', '1'], trailing: 'U6100 Server'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '001'), $connection);

        self::assertContains(':002 EOS', $this->written);
        self::assertContains(':002 DB 001 HEL 4 services.test.local', $this->written);
    }

    #[Test]
    public function helFromThePeerBeforeOurEosStillGetsAckedAndOurHelGoesOut(): void
    {
        $this->written = [];
        $handler = $this->createHandler();
        $connection = $this->createConnection();

        $handler->performHandshake($connection, $this->createServerLink());
        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['NOQUIT', 'SID=001']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['ircd.example.net', '1'], trailing: 'IRCd'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'HEL', '4', 'services.test.local']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '001'), $connection);

        self::assertContains(':002 DB 001 HEL 4 ACK', $this->written);
        self::assertContains(':002 DB 001 HEL 4 services.test.local', $this->written);
    }

    #[Test]
    public function incomingDbFramesAreDelegatedToTheCoordinator(): void
    {
        $this->written = [];
        $handler = $this->createHandler();
        $connection = $this->createConnection();

        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'HEL', '4', 'ircd.example.net']), $connection);

        self::assertSame([':002 DB 001 HEL 4 ACK'], $this->written);
    }

    #[Test]
    public function malformedDbFramesAreIgnored(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $coordinator = new UdbSessionCoordinator(
            '002',
            $this->createStub(UdbBlockStateRepositoryInterface::class),
            $this->createStub(UdbSnapshotProviderInterface::class),
            $logger,
        );
        $handler = new UnrealUdbProtocolHandler('002', $coordinator, $logger);

        $this->written = [];
        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'BOGUS']), $this->createConnection());
        $handler->handleIncoming(new IRCMessage(command: 'DB', prefix: '001', params: ['002', 'INF', '0', 'N', '00', '1']), $this->createConnection());

        self::assertSame([], $this->written);
    }

    #[Test]
    public function netinfoIsAnswered(): void
    {
        $this->written = [];
        $handler = $this->createHandler();

        $handler->handleIncoming(new IRCMessage(command: 'NETINFO', params: ['0', '1', '6100', '*', '0', '0', '0'], trailing: 'Net'), $this->createConnection());

        self::assertMatchesRegularExpression('/^NETINFO 0 \d+ 6100 \* 0 0 0 :Net$/', $this->written[0]);
    }

    #[Test]
    public function unrelatedCommandsFallThroughWithoutUdbSideEffects(): void
    {
        $this->written = [];
        $handler = $this->createHandler();

        $handler->handleIncoming(new IRCMessage(command: 'PRIVMSG', prefix: '001', params: ['002'], trailing: 'hi'), $this->createConnection());

        self::assertSame([], $this->written);
    }

    #[Test]
    public function pingIsAnsweredWithPong(): void
    {
        $this->written = [];
        $handler = $this->createHandler();

        $handler->handleIncoming(new IRCMessage(command: 'PING', trailing: 'token'), $this->createConnection());

        self::assertSame(['PONG :token'], $this->written);
    }
}
