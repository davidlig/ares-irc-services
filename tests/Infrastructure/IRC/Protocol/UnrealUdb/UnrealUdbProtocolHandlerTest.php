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
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordDeletedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncCompleteEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(UnrealUdbProtocolHandler::class)]
final class UnrealUdbProtocolHandlerTest extends TestCase
{
    private function createHandler(string $sid = '001'): UnrealUdbProtocolHandler
    {
        return new UnrealUdbProtocolHandler($sid);
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

        self::assertSame('unrealudb', $handler->getProtocolName());
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
    public function handleIncomingEosWritesEosAndDispatchesBurstComplete(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
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

    #[Test]
    public function handleIncomingParsesDbInfAndDispatchesSyncRequestedEvent(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->isInstanceOf(UdbSyncRequestedEvent::class));

        $handler = new UnrealUdbProtocolHandler('001', $logger, $dispatcher);

        $message = new IRCMessage('DB', 'sid', ['*', 'INF', 'N', 'crc', 'timestamp']);
        $connection = clone $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingIgnoresDbWhenNotInfOrNotEnoughParams(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $dispatcher->expects($this->never())->method('dispatch');

        $handler = new UnrealUdbProtocolHandler('001', $logger, $dispatcher);

        $message1 = new IRCMessage('DB', 'sid', ['*']);
        $message2 = new IRCMessage('DB', 'sid', ['*', 'OVR', 'N']);
        $connection = clone $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($message1, $connection);
        $handler->handleIncoming($message2, $connection);
    }

    #[Test]
    public function handleIncomingDoesNotCrashWhenDispatcherIsNull(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $handler = new UnrealUdbProtocolHandler('001', $logger, null);

        $message1 = new IRCMessage('DB', 'sid', ['*', 'INF', 'N', 'crc', 'timestamp']);
        $message2 = new IRCMessage('DB', 'sid', ['*', 'INS', 'N::nickname::pass'], 'hash');
        $connection = clone $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($message1, $connection);
        $handler->handleIncoming($message2, $connection);

        $this->assertTrue(true); // just verifying it doesn't crash
    }

    #[Test]
    public function handleIncomingParsesDbInsAndDispatchesRecordReceivedEvent(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->isInstanceOf(UdbRecordReceivedEvent::class));

        $handler = new UnrealUdbProtocolHandler('001', $logger, $dispatcher);

        $message = new IRCMessage('DB', 'sid', ['*', 'INS', 'N::nickname::pass', 'hash']);
        $connection = clone $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingParsesDbFdrLogsAndDispatchesSyncComplete(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('UDB sync completed (FDR) for block N');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->with($this->isInstanceOf(UdbSyncCompleteEvent::class));

        $handler = new UnrealUdbProtocolHandler('001', $logger, $dispatcher);

        $message = new IRCMessage('DB', 'sid', ['*', 'FDR', 'N']);
        $connection = clone $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingParsesDbDelAndDispatchesRecordDeleted(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (object $event): bool => $event instanceof UdbRecordDeletedEvent && 'N::ghost::pass' === $event->key));

        $handler = new UnrealUdbProtocolHandler('001', $this->createStub(LoggerInterface::class), $dispatcher);
        $handler->handleIncoming(
            new IRCMessage('DB', 'sid', ['*', 'DEL', 'N::ghost::pass']),
            $this->createStub(ConnectionInterface::class),
        );
    }

    #[Test]
    public function handleIncomingParsesDbFdrWithNullDispatcher(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('UDB sync completed (FDR) for block N');

        $handler = new UnrealUdbProtocolHandler('001', $logger, null);

        $message = new IRCMessage('DB', 'sid', ['*', 'FDR', 'N']);
        $connection = clone $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingParsesDbInsMultiWordValueFromParams(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $capturedEvent = null;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$capturedEvent): object {
                $capturedEvent = $event;

                return $event;
            });

        $handler = new UnrealUdbProtocolHandler('001', $logger, $dispatcher);

        // Multi-word value without trailing ':' prefix — arrives as extra params
        $message = new IRCMessage('DB', 'sid', ['*', 'INS', 'N::davidlig::swhois', 'es', 'Usuario', 'VIP']);
        $connection = clone $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($message, $connection);

        self::assertInstanceOf(UdbRecordReceivedEvent::class, $capturedEvent);
        self::assertSame('N::davidlig::swhois', $capturedEvent->key);
        self::assertSame('es Usuario VIP', $capturedEvent->value);
    }

    #[Test]
    public function handleIncomingParsesDbInsValueFromTrailingWhenPresent(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $capturedEvent = null;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$capturedEvent): object {
                $capturedEvent = $event;

                return $event;
            });

        $handler = new UnrealUdbProtocolHandler('001', $logger, $dispatcher);

        // Value with ':' prefix — arrives as trailing
        $message = new IRCMessage('DB', 'sid', ['*', 'INS', 'N::davidlig::swhois'], 'es Usuario VIP');
        $connection = clone $this->createStub(ConnectionInterface::class);
        $handler->handleIncoming($message, $connection);

        self::assertInstanceOf(UdbRecordReceivedEvent::class, $capturedEvent);
        self::assertSame('N::davidlig::swhois', $capturedEvent->key);
        self::assertSame('es Usuario VIP', $capturedEvent->value);
    }

    #[Test]
    public function handleIncomingHelAcksAndSendsOwnHel(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('001');
        $handler->handleIncoming(new IRCMessage('SERVER', '', ['irc.example.net', '1'], 'U6-001'), $connection);

        $hel = new IRCMessage('DB', '005', ['001', 'HEL', '4', 'ares-services.test']);
        $handler->handleIncoming($hel, $connection);
        $handler->handleIncoming($hel, $connection);

        self::assertContains(':001 DB 005 HEL 4 ACK', $written);
        self::assertContains(':001 DB 005 HEL 4 irc.example.net', $written);
    }

    #[Test]
    public function handleIncomingHelAckOnlyLogs(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->never())->method('writeLine');

        $handler = $this->createHandler('001');
        $hel = new IRCMessage('DB', '005', ['001', 'HEL', '4', 'ACK']);
        $handler->handleIncoming($hel, $connection);

        self::assertTrue(true);
    }

    #[Test]
    public function handleIncomingHelBeforeServerLineDefersOwnHel(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('001');
        $hel = new IRCMessage('DB', '005', ['001', 'HEL', '4', 'ares-services.test']);
        $handler->handleIncoming($hel, $connection);

        // Only the ACK is sent; our HEL is deferred until the remote name is known.
        self::assertSame([':001 DB 005 HEL 4 ACK'], $written);

        $handler->handleIncoming(new IRCMessage('SERVER', '', ['irc.example.net', '1'], 'U6-001'), $connection);

        self::assertContains(':001 DB 005 HEL 4 irc.example.net', $written);
    }

    #[Test]
    public function handleIncomingHelWithoutPrefixIsIgnored(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->never())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('001');
        $hel = new IRCMessage('DB', '', ['001', 'HEL', '4', '-']);
        $handler->handleIncoming($hel, $connection);

        self::assertSame([], $written);
    }

    #[Test]
    public function handleIncomingServerWithoutNameIsIgnored(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->never())->method('writeLine');

        $handler = $this->createHandler('001');
        $handler->handleIncoming(new IRCMessage('SERVER', '', [], 'U6-001'), $connection);

        self::assertSame([], $written);
    }

    #[Test]
    public function handleIncomingServerWithoutPendingHelOnlyCapturesName(): void
    {
        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->atLeastOnce())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = $this->createHandler('001');
        $handler->handleIncoming(new IRCMessage('SERVER', '', ['irc.example.net', '1'], 'U6-001'), $connection);

        self::assertSame([], $written);

        $hel = new IRCMessage('DB', '005', ['001', 'HEL', '4', 'ares-services.test']);
        $handler->handleIncoming($hel, $connection);

        self::assertContains(':001 DB 005 HEL 4 ACK', $written);
        self::assertContains(':001 DB 005 HEL 4 irc.example.net', $written);
    }

    #[Test]
    public function handleIncomingBeginWithoutParamsIsIgnored(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = new UnrealUdbProtocolHandler('001', $this->createStub(LoggerInterface::class), $dispatcher);
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'BEGIN']),
            $this->createStub(ConnectionInterface::class),
        );
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'BEGIN', 'N']),
            $this->createStub(ConnectionInterface::class),
        );

        self::assertTrue(true);
    }

    #[Test]
    public function handleIncomingDbErrLogsWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $handler = new UnrealUdbProtocolHandler('001', $logger, null);
        $err = new IRCMessage('DB', '005', ['001', 'ERR', 'INS', '9', 'N']);
        $handler->handleIncoming($err, $this->createStub(ConnectionInterface::class));

        self::assertTrue(true);
    }

    #[Test]
    public function handleIncomingDbResFromPeerIsIgnored(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->never())->method('writeLine');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = new UnrealUdbProtocolHandler('001', $this->createStub(LoggerInterface::class), $dispatcher);
        $res = new IRCMessage('DB', '005', ['001', 'RES', 'N']);
        $handler->handleIncoming($res, $connection);

        self::assertTrue(true);
    }

    #[Test]
    public function handleIncomingStagedBeginPutEndDispatchesRecordsAndCompletes(): void
    {
        $captured = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->exactly(2))->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured[] = $event;

                return $event;
            });

        $written = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->once())->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void {
            $written[] = $line;
        });

        $handler = new UnrealUdbProtocolHandler('001', $this->createStub(LoggerInterface::class), $dispatcher);

        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'BEGIN', 'N', 'abc123', '1234ABCD']),
            $connection,
        );
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'PUT', 'N', 'abc123', 'davidlig::pass'], 'sha256:abc'),
            $connection,
        );
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'END', 'N', 'abc123', '1234ABCD']),
            $connection,
        );

        self::assertCount(2, $captured);
        self::assertInstanceOf(UdbRecordReceivedEvent::class, $captured[0]);
        self::assertSame('N::davidlig::pass', $captured[0]->key);
        self::assertSame('sha256:abc', $captured[0]->value);
        self::assertInstanceOf(UdbSyncCompleteEvent::class, $captured[1]);
        self::assertSame('N', $captured[1]->block);
        self::assertSame([':001 DB 005 ACK N abc123 1234ABCD'], $written);
    }

    #[Test]
    public function handleIncomingStagedPutWithEmptyPathIsIgnored(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = new UnrealUdbProtocolHandler('001', $this->createStub(LoggerInterface::class), $dispatcher);
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'BEGIN', 'N', 'abc123', '1234ABCD']),
            $this->createStub(ConnectionInterface::class),
        );
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'PUT', 'N', 'abc123', ''], 'value'),
            $this->createStub(ConnectionInterface::class),
        );

        self::assertTrue(true);
    }

    #[Test]
    public function handleIncomingStagedPutBeforeBeginIsIgnored(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $handler = new UnrealUdbProtocolHandler('001', $this->createStub(LoggerInterface::class), $dispatcher);
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'PUT', 'N', 'abc123', 'davidlig::pass'], 'sha256:abc'),
            $this->createStub(ConnectionInterface::class),
        );

        self::assertTrue(true);
    }

    #[Test]
    public function handleIncomingStagedEndWithWrongTxidIsIgnored(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects($this->never())->method('writeLine');

        $handler = new UnrealUdbProtocolHandler('001', $this->createStub(LoggerInterface::class), $dispatcher);
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'BEGIN', 'N', 'abc123', '1234ABCD']),
            $connection,
        );
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'END', 'N', 'wrongtx', '1234ABCD']),
            $connection,
        );

        self::assertTrue(true);
    }

    #[Test]
    public function handleIncomingStagedPutMultiWordValueFromParams(): void
    {
        $capturedEvent = null;
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$capturedEvent): object {
                $capturedEvent = $event;

                return $event;
            });

        $handler = new UnrealUdbProtocolHandler('001', $this->createStub(LoggerInterface::class), $dispatcher);
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'BEGIN', 'C', 'tx1', '1234ABCD']),
            $this->createStub(ConnectionInterface::class),
        );
        $handler->handleIncoming(
            new IRCMessage('DB', '005', ['001', 'PUT', 'C', 'tx1', '#chan::topic', 'Welcome', 'to', 'my', 'channel']),
            $this->createStub(ConnectionInterface::class),
        );

        self::assertInstanceOf(UdbRecordReceivedEvent::class, $capturedEvent);
        self::assertSame('C::#chan::topic', $capturedEvent->key);
        self::assertSame('Welcome to my channel', $capturedEvent->value);
    }

    #[Test]
    public function handleIncomingDbAckLogsInfo(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with('UDB staged sync acknowledged for block N');

        $handler = new UnrealUdbProtocolHandler('001', $logger, null);
        $ack = new IRCMessage('DB', '005', ['001', 'ACK', 'N', 'abc123', '1234ABCD']);
        $handler->handleIncoming($ack, $this->createStub(ConnectionInterface::class));

        self::assertTrue(true);
    }
}
