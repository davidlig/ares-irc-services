<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneProtocolHandler;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(UnrealStandaloneProtocolHandler::class)]
final class UnrealStandaloneProtocolHandlerTest extends TestCase
{
    /** @var list<string> */
    private array $effects = [];

    private function createHandler(string $sid = '001', ?EventDispatcherInterface $eventDispatcher = null): UnrealStandaloneProtocolHandler
    {
        return new UnrealStandaloneProtocolHandler(sid: $sid, eventDispatcher: $eventDispatcher);
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
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$lines): void {
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
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler();
        $msg = new IRCMessage(command: 'PING', trailing: 'token123');

        $handler->handleIncoming($msg, $connection);

        self::assertSame(['PONG :token123'], $written);
    }

    #[Test]
    public function handleIncomingErrorWritesNothing(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $this->createHandler()->handleIncoming(
            new IRCMessage(command: 'ERROR', trailing: 'Ping timeout'),
            $connection,
        );
    }

    #[Test]
    public function onlyDirectPeerEosCompletesTheBurstOncePerHandshake(): void
    {
        $this->effects = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->effects[] = 'write:' . $line;
        });

        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')
            ->willReturnCallback(function (NetworkBurstCompleteEvent|NetworkSyncCompleteEvent $event) use ($connection): object {
                self::assertSame($connection, $event->connection);
                self::assertSame('005', $event->serverSid);
                $this->effects[] = 'event:' . $event::class;

                return $event;
            });

        $handler = $this->createHandler('005', $eventDispatcher);
        $handler->performHandshake($connection, $this->createServerLink());
        $this->effects = [];

        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', prefix: '0A4', params: ['SID=0A4']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '0A4'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['NOQUIT', 'SID=0A1']), $connection);

        foreach (['0A4', '0A5', '0A2', '0A3'] as $downstreamSid) {
            $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: $downstreamSid), $connection);
        }

        self::assertSame([], $this->effects);

        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '0A1'), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '0A1'), $connection);

        self::assertSame([
            'event:' . NetworkBurstCompleteEvent::class,
            'write::005 EOS',
            'event:' . NetworkSyncCompleteEvent::class,
        ], $this->effects);

        $handler->performHandshake($connection, $this->createServerLink());
        $this->effects = [];

        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '0A1'), $connection);
        self::assertSame([], $this->effects);

        $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['SID=0B1']), $connection);
        $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '0B1'), $connection);

        self::assertSame([
            'event:' . NetworkBurstCompleteEvent::class,
            'write::005 EOS',
            'event:' . NetworkSyncCompleteEvent::class,
        ], $this->effects);
    }

    #[Test]
    public function handleIncomingNetinfoWritesNetinfoLine(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
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
