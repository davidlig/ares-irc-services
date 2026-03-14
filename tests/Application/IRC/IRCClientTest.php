<?php

declare(strict_types=1);

namespace App\Tests\Application\IRC;

use App\Application\IRC\BurstCompleteRegistry;
use App\Application\IRC\IRCClient;
use App\Application\Maintenance\Message\RunMaintenanceCycle;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(IRCClient::class)]
final class IRCClientTest extends TestCase
{
    private ConnectionInterface $connection;

    private ProtocolHandlerInterface $protocol;

    private EventDispatcherInterface $eventDispatcher;

    private MessageBusInterface $messageBus;

    private BurstCompleteRegistry $burstCompleteRegistry;

    private IRCClient $client;

    private ServerLink $link;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->connection->method('isConnected')->willReturn(false);
        $this->connection->method('readLine')->willReturn(null);

        $this->protocol = $this->createStub(ProtocolHandlerInterface::class);
        $this->protocol->method('getProtocolName')->willReturn('unreal');

        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->messageBus = $this->createStub(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturn(new Envelope(new stdClass()));

        $this->burstCompleteRegistry = new BurstCompleteRegistry();
        $this->link = new ServerLink(
            new ServerName('irc.test.local'),
            new Hostname('127.0.0.1'),
            new Port(7000),
            new LinkPassword('secret'),
            'Test Server',
            false,
        );

        $this->client = $this->createClient();
    }

    private function createClient(): IRCClient
    {
        return new IRCClient(
            $this->connection,
            $this->protocol,
            $this->eventDispatcher,
            $this->messageBus,
            $this->burstCompleteRegistry,
            60,
        );
    }

    #[Test]
    public function connectCallsConnectionAndHandshakeAndDispatchesConnectionEstablished(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::once())->method('connect');
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::once())->method('performHandshake')
            ->with($this->connection, $this->link);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof ConnectionEstablishedEvent));
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->client = $this->createClient();

        $this->client->connect($this->link);
    }

    #[Test]
    public function getProtocolNameDelegatesToProtocol(): void
    {
        self::assertSame('unreal', $this->client->getProtocolName());
    }

    #[Test]
    public function runProcessesOneLineThenExitsWhenConnectionCloses(): void
    {
        $rawLine = ':server PING 12345';
        $message = new IRCMessage('PING', 'server', ['12345']);
        $dispatched = [];

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::exactly(2))->method('isConnected')->willReturn(true, false);
        $this->connection->expects(self::once())->method('readLine')->willReturn($rawLine);
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::once())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::once())->method('handleIncoming')->with($message, $this->connection);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::atLeastOnce())->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            }
        );
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->client = $this->createClient();

        $this->client->run();

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(MessageReceivedEvent::class, $dispatched[0]);
        self::assertSame($message, $dispatched[0]->message);
        self::assertInstanceOf(IrcMessageProcessedEvent::class, $dispatched[1]);
    }

    #[Test]
    public function runExitsImmediatelyWhenNotConnected(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(false);
        $this->connection->expects(self::never())->method('readLine');
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::never())->method('parseRawLine');
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::never())->method('dispatch');
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->client = $this->createClient();

        $this->client->run();
    }

    #[Test]
    public function runSkipsEmptyLines(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true, true, false);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturn('', ':s PING x');
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::once())->method('parseRawLine')->with(':s PING x')
            ->willReturn(new IRCMessage('PING', 's', ['x']));
        $this->protocol->expects(self::once())->method('handleIncoming');
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::exactly(2))->method('dispatch')->willReturnArgument(0);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->client = $this->createClient();

        $this->client->run();
    }

    #[Test]
    public function runSkipsNullReadLineAndContinues(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true, true, false);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturn(null, ':s PING y');
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::once())->method('parseRawLine')->with(':s PING y')
            ->willReturn(new IRCMessage('PING', 's', ['y']));
        $this->protocol->expects(self::once())->method('handleIncoming');
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::exactly(2))->method('dispatch')->willReturnArgument(0);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->client = $this->createClient();

        $this->client->run();
    }

    #[Test]
    public function runDispatchesMaintenanceCycleWhenBurstCompleteAndIntervalElapsed(): void
    {
        $this->burstCompleteRegistry->setBurstComplete(true);
        $rawLine = ':s PING x';
        $message = new IRCMessage('PING', 's', ['x']);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true, true, false);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturn($rawLine, $rawLine);
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::atLeastOnce())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::atLeastOnce())->method('handleIncoming');
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::atLeastOnce())->method('dispatch')->willReturnArgument(0);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($m): bool => $m instanceof RunMaintenanceCycle))
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));
        $this->client = $this->createClient();

        $this->client->run();
    }

    #[Test]
    public function runDoesNotDispatchMaintenanceWhenBurstNotComplete(): void
    {
        $this->burstCompleteRegistry->setBurstComplete(false);
        $rawLine = ':s PING x';
        $message = new IRCMessage('PING', 's', ['x']);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true, false);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturn($rawLine);
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::atLeastOnce())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::atLeastOnce())->method('handleIncoming');
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::atLeastOnce())->method('dispatch')->willReturnArgument(0);
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->client = $this->createClient();

        $this->client->run();
    }

    #[Test]
    public function disconnectDispatchesConnectionLostWhenActiveLinkSetAndDisconnects(): void
    {
        $dispatched = [];
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::once())->method('connect');
        $this->connection->expects(self::once())->method('disconnect');
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::once())->method('performHandshake')->with($this->connection, $this->link);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->eventDispatcher->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            }
        );
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');
        $this->client = $this->createClient();

        $this->client->connect($this->link);
        $this->client->disconnect('test reason');

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(ConnectionLostEvent::class, $dispatched[1]);
        self::assertSame('test reason', $dispatched[1]->reason);
    }

    #[Test]
    public function disconnectOnlyDisconnectsWhenNoActiveLink(): void
    {
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::once())->method('disconnect');
        $this->protocol = $this->createStub(ProtocolHandlerInterface::class);
        $this->protocol->method('getProtocolName')->willReturn('unreal');
        $this->eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);
        $this->messageBus = $this->createStub(MessageBusInterface::class);
        $this->messageBus->method('dispatch')->willReturn(new Envelope(new stdClass()));
        $this->client = $this->createClient();

        $this->client->disconnect();
    }
}
