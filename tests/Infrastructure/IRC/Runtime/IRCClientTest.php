<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Runtime;

use App\Application\IRC\BurstCompleteRegistry;
use App\Application\Maintenance\Message\RunMaintenanceCycle;
use App\Application\Port\AsyncMessageDispatcherInterface;
use App\Application\Port\EventBusInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Event\MessageReceivedEvent;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use App\Infrastructure\IRC\Runtime\IRCClient;
use App\Infrastructure\IRC\Runtime\LoopSchedulerInterface;
use App\Infrastructure\IRC\Runtime\RevoltLoopScheduler;
use App\Infrastructure\IRC\Runtime\SessionEventPump;
use App\Infrastructure\IRC\Runtime\SessionEventPumpAwareInterface;
use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;

use function array_filter;
use function array_values;

#[CoversClass(IRCClient::class)]
final class IRCClientTest extends TestCase
{
    private ConnectionInterface $connection;

    private ProtocolHandlerInterface $protocol;

    private EventBusInterface $eventDispatcher;

    private AsyncMessageDispatcherInterface $messageBus;

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

        $this->eventDispatcher = $this->createStub(EventBusInterface::class);
        $this->eventDispatcher->method('dispatch')->willReturnArgument(0);

        $this->messageBus = $this->createStub(AsyncMessageDispatcherInterface::class);
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

    private function createClient(
        ?LoopSchedulerInterface $loopScheduler = null,
        ?SessionEventPump $eventPump = null,
    ): IRCClient {
        return new IRCClient(
            $this->connection,
            $this->protocol,
            $this->eventDispatcher,
            $this->messageBus,
            $this->burstCompleteRegistry,
            60,
            loopScheduler: $loopScheduler ?? new RevoltLoopScheduler(),
            eventPump: $eventPump,
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
        $this->eventDispatcher = $this->createMock(EventBusInterface::class);
        $this->eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::isInstanceOf(ConnectionEstablishedEvent::class));
        $this->client = $this->createClient();

        $this->client->connect($this->link);
    }

    #[Test]
    public function connectFailsAndClearsEventPumpAwareProtocolWhenHandshakeThrows(): void
    {
        $awareProtocol = new class extends AbstractProtocolHandler implements SessionEventPumpAwareInterface {
            public ?SessionEventPump $pump = null;

            public function getProtocolName(): string
            {
                return 'custom';
            }

            public function performHandshake(ConnectionInterface $connection, ServerLink $link): void
            {
                throw new RuntimeException('Handshake failed');
            }

            public function setEventPump(?SessionEventPump $eventPump): void
            {
                $this->pump = $eventPump;
            }
        };

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::once())->method('connect');
        $this->connection->expects(self::once())->method('disconnect');

        $this->client = new IRCClient(
            $this->connection,
            $awareProtocol,
            $this->eventDispatcher,
            $this->messageBus,
            $this->burstCompleteRegistry,
            60,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handshake failed');

        try {
            $this->client->connect($this->link);
        } finally {
            self::assertNull($awareProtocol->pump);
        }
    }

    #[Test]
    public function getProtocolNameDelegatesToProtocol(): void
    {
        self::assertSame('unreal', $this->client->getProtocolName());
    }

    #[Test]
    public function getEventPumpReturnsEventPump(): void
    {
        $pump = new SessionEventPump();
        $client = $this->createClient(eventPump: $pump);
        self::assertSame($pump, $client->getEventPump());
    }

    #[Test]
    public function runProcessesLinesSequentiallyThenExitsOnEof(): void
    {
        $line1 = ':server PING 12345';
        $line2 = ':server PONG 12345';
        $msg1 = new IRCMessage('PING', 'server', ['12345']);
        $msg2 = new IRCMessage('PONG', 'server', ['12345']);
        $dispatched = [];

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturn($line1, $line2, null);

        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::exactly(2))->method('parseRawLine')
            ->willReturnCallback(static fn (string $line): IRCMessage => match ($line) {
                $line1 => $msg1,
                $line2 => $msg2,
                default => new IRCMessage('UNKNOWN'),
            });
        $this->protocol->expects(self::exactly(2))->method('handleIncoming');

        $this->eventDispatcher = $this->createMock(EventBusInterface::class);
        $this->eventDispatcher->expects(self::atLeastOnce())->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            }
        );

        $this->client = $this->createClient();
        $this->client->run();

        self::assertCount(4, $dispatched);
        self::assertInstanceOf(MessageReceivedEvent::class, $dispatched[0]);
        self::assertSame($msg1, $dispatched[0]->message);
        self::assertInstanceOf(IrcMessageProcessedEvent::class, $dispatched[1]);
        self::assertInstanceOf(MessageReceivedEvent::class, $dispatched[2]);
        self::assertSame($msg2, $dispatched[2]->message);
        self::assertInstanceOf(IrcMessageProcessedEvent::class, $dispatched[3]);
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

        $this->client = $this->createClient();
        $this->client->run();
    }

    #[Test]
    public function runSkipsEmptyLines(): void
    {
        $rawLine = ':s PING x';
        $message = new IRCMessage('PING', 's', ['x']);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturn('', $rawLine, '', null);

        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::once())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::once())->method('handleIncoming');

        $this->eventDispatcher = $this->createMock(EventBusInterface::class);
        $this->eventDispatcher->expects(self::exactly(2))->method('dispatch')->willReturnArgument(0);

        $this->client = $this->createClient();
        $this->client->run();
    }

    #[Test]
    public function remoteEofTriggersCompleteCleanupAndExits(): void
    {
        $dispatchedEvents = [];
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::once())->method('connect');
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true);
        $this->connection->expects(self::once())->method('readLine')->willReturn(null);
        $this->connection->expects(self::once())->method('disconnect');

        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::once())->method('performHandshake')->with($this->connection, $this->link);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');

        $this->eventDispatcher = $this->createMock(EventBusInterface::class);
        $this->eventDispatcher->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            }
        );

        $this->client = $this->createClient();
        $this->client->connect($this->link);
        $this->client->run();

        $lostEvents = array_filter($dispatchedEvents, static fn ($e): bool => $e instanceof ConnectionLostEvent);
        self::assertCount(1, $lostEvents, 'ConnectionLostEvent must be dispatched exactly once on EOF');
        $lostEvent = array_values($lostEvents)[0];
        self::assertSame('Remote host closed connection', $lostEvent->reason);
    }

    #[Test]
    public function signalPlusFinallyDispatchesConnectionLostEventExactlyOnce(): void
    {
        $rawLine = ':server PING 12345';
        $message = new IRCMessage('PING', 'server', ['12345']);
        $dispatchedEvents = [];
        $connected = true;

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::once())->method('connect');
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturnCallback(static fn (): bool => $connected);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturnCallback(static function () use ($rawLine): string {
            \Amp\delay(0.001);

            return $rawLine;
        });
        $this->connection->expects(self::once())->method('disconnect')->willReturnCallback(static function () use (&$connected): void {
            $connected = false;
        });

        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::once())->method('performHandshake')->with($this->connection, $this->link);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::once())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::once())->method('handleIncoming')->willReturnCallback(function (): void {
            // Simulate signal handler firing while client is running
            $this->client->disconnect('SIGTERM');
        });

        $this->eventDispatcher = $this->createMock(EventBusInterface::class);
        $this->eventDispatcher->expects(self::exactly(4))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            }
        );

        $this->client = $this->createClient();
        $this->client->connect($this->link);
        $this->client->run();

        $lostEvents = array_filter($dispatchedEvents, static fn ($e): bool => $e instanceof ConnectionLostEvent);
        self::assertCount(1, $lostEvents, 'ConnectionLostEvent must be dispatched exactly once');
        $lostEvent = array_values($lostEvents)[0];
        self::assertSame('SIGTERM', $lostEvent->reason);
    }

    #[Test]
    public function firstMaintenanceDispatchedImmediatelyAfterBurstCompleteAndRepeatedViaScheduler(): void
    {
        $this->burstCompleteRegistry->setBurstComplete(true);
        $rawLine = ':server PING 12345';
        $message = new IRCMessage('PING', 'server', ['12345']);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturn($rawLine, null);

        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::once())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::once())->method('handleIncoming');

        $this->messageBus = $this->createMock(AsyncMessageDispatcherInterface::class);
        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::isInstanceOf(RunMaintenanceCycle::class))
            ->willReturn(new Envelope(new stdClass()));

        $scheduler = $this->createMock(LoopSchedulerInterface::class);
        $scheduler->expects(self::once())->method('repeat')
            ->with(60.0, self::isInstanceOf(Closure::class))
            ->willReturn('watcher-123');
        $scheduler->expects(self::once())->method('cancel')->with('watcher-123');

        $this->client = $this->createClient(loopScheduler: $scheduler);
        $this->client->run();
    }

    #[Test]
    public function maintenanceDispatchedImmediatelyWhenBurstBecomesCompleteDuringRun(): void
    {
        $this->burstCompleteRegistry->setBurstComplete(false);
        $rawLine = ':server EOS';
        $message = new IRCMessage('EOS', 'server', []);
        $called = 0;

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturnCallback(
            static function () use (&$called, $rawLine): ?string {
                ++$called;
                if (1 === $called) {
                    return $rawLine;
                }
                \Amp\delay(0.01);

                return null;
            }
        );

        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::once())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::once())->method('handleIncoming')->willReturnCallback(function (): void {
            $this->burstCompleteRegistry->setBurstComplete(true);
        });

        $this->messageBus = $this->createMock(AsyncMessageDispatcherInterface::class);
        $this->messageBus->expects(self::once())->method('dispatch')
            ->with(self::isInstanceOf(RunMaintenanceCycle::class))
            ->willReturn(new Envelope(new stdClass()));

        $scheduler = $this->createMock(LoopSchedulerInterface::class);
        $scheduler->expects(self::once())->method('repeat')
            ->with(60.0, self::isInstanceOf(Closure::class))
            ->willReturn('watcher-burst');
        $scheduler->expects(self::once())->method('cancel')->with('watcher-burst');

        $this->client = $this->createClient(loopScheduler: $scheduler);
        $this->client->run();
    }

    #[Test]
    public function maintenanceRepeatCallbackEnqueuesMaintenance(): void
    {
        $this->burstCompleteRegistry->setBurstComplete(true);
        $rawLine = ':server PING 12345';
        $message = new IRCMessage('PING', 'server', ['12345']);
        $called = 0;

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturnCallback(
            static function () use (&$called, $rawLine): ?string {
                ++$called;
                if (1 === $called) {
                    return $rawLine;
                }
                \Amp\delay(0.01);

                return null;
            }
        );

        $repeatCallbackInvocations = 0;
        $repeatCallback = static function () use (&$repeatCallbackInvocations): void {
            ++$repeatCallbackInvocations;
        };
        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::once())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::once())->method('handleIncoming')->willReturnCallback(static function () use (&$repeatCallback): void {
            $repeatCallback();
        });

        $this->messageBus = $this->createMock(AsyncMessageDispatcherInterface::class);
        $this->messageBus->expects(self::exactly(2))->method('dispatch')
            ->with(self::isInstanceOf(RunMaintenanceCycle::class))
            ->willReturn(new Envelope(new stdClass()));

        $scheduler = $this->createMock(LoopSchedulerInterface::class);
        $scheduler->expects(self::once())->method('repeat')
            ->willReturnCallback(static function (float $interval, Closure $callback) use (&$repeatCallback): string {
                $repeatCallback = $callback;

                return 'watcher-repeat';
            });
        $scheduler->expects(self::once())->method('cancel')->with('watcher-repeat');

        $this->client = $this->createClient(loopScheduler: $scheduler);
        $this->client->run();
    }

    #[Test]
    public function maintenanceNotDispatchedWhenBurstNotComplete(): void
    {
        $this->burstCompleteRegistry->setBurstComplete(false);
        $rawLine = ':s PING x';
        $message = new IRCMessage('PING', 's', ['x']);

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true);
        $this->connection->expects(self::atLeastOnce())->method('readLine')->willReturn($rawLine, null);

        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');
        $this->protocol->expects(self::atLeastOnce())->method('parseRawLine')->with($rawLine)->willReturn($message);
        $this->protocol->expects(self::atLeastOnce())->method('handleIncoming');

        $this->messageBus = $this->createMock(AsyncMessageDispatcherInterface::class);
        $this->messageBus->expects(self::never())->method('dispatch');

        $this->client = $this->createClient();
        $this->client->run();
    }

    #[Test]
    public function readExceptionPropagatesAndTriggersSessionTermination(): void
    {
        $dispatchedEvents = [];
        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::once())->method('connect');
        $this->connection->expects(self::atLeastOnce())->method('isConnected')->willReturn(true);
        $this->connection->expects(self::once())->method('readLine')->willThrowException(
            new RuntimeException('Connection reset by peer')
        );
        $this->connection->expects(self::once())->method('disconnect');

        $this->protocol = $this->createMock(ProtocolHandlerInterface::class);
        $this->protocol->expects(self::once())->method('performHandshake')->with($this->connection, $this->link);
        $this->protocol->expects(self::atLeastOnce())->method('getProtocolName')->willReturn('unreal');

        $this->eventDispatcher = $this->createMock(EventBusInterface::class);
        $this->eventDispatcher->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            }
        );

        $this->client = $this->createClient();
        $this->client->connect($this->link);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection reset by peer');

        try {
            $this->client->run();
        } finally {
            $lostEvents = array_filter($dispatchedEvents, static fn ($e): bool => $e instanceof ConnectionLostEvent);
            self::assertCount(1, $lostEvents);
            $lostEvent = array_values($lostEvents)[0];
            self::assertSame('Connection reset by peer', $lostEvent->reason);
        }
    }

    #[Test]
    public function sessionEventPumpAwareProtocolGetsPumpOnConnectAndClearedOnTermination(): void
    {
        $awareProtocol = new class extends AbstractProtocolHandler implements SessionEventPumpAwareInterface {
            public ?SessionEventPump $pump = null;

            public function getProtocolName(): string
            {
                return 'custom';
            }

            public function performHandshake(ConnectionInterface $connection, ServerLink $link): void {}

            public function setEventPump(?SessionEventPump $eventPump): void
            {
                $this->pump = $eventPump;
            }
        };

        $this->connection = $this->createMock(ConnectionInterface::class);
        $this->connection->expects(self::once())->method('connect');
        $this->connection->expects(self::once())->method('disconnect');

        $this->client = new IRCClient(
            $this->connection,
            $awareProtocol,
            $this->eventDispatcher,
            $this->messageBus,
            $this->burstCompleteRegistry,
            60,
        );

        self::assertSame($this->client->getEventPump(), $awareProtocol->pump);

        $this->client->connect($this->link);
        self::assertSame($this->client->getEventPump(), $awareProtocol->pump);

        $this->client->disconnect('closing');
        self::assertNull($awareProtocol->pump);
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
        $this->eventDispatcher = $this->createMock(EventBusInterface::class);
        $this->eventDispatcher->expects(self::exactly(2))->method('dispatch')->willReturnCallback(
            static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            }
        );
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
        $this->client = $this->createClient();

        $this->client->disconnect();
    }
}
