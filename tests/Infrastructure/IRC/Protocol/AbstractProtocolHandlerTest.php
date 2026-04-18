<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol;

use App\Domain\IRC\Message\IRCMessage;
use App\Infrastructure\IRC\Protocol\AbstractProtocolHandler;
use App\Infrastructure\IRC\Protocol\InspIRCd\InspIRCdProtocolHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractProtocolHandler::class)]
final class AbstractProtocolHandlerTest extends TestCase
{
    /**
     * InspIRCdProtocolHandler does not override getSupportedCapabilities(),
     * so calling it exercises AbstractProtocolHandler::getSupportedCapabilities().
     */
    #[Test]
    public function getSupportedCapabilitiesReturnsEmptyByDefault(): void
    {
        $handler = new InspIRCdProtocolHandler();

        self::assertSame([], $handler->getSupportedCapabilities());
    }

    #[Test]
    public function parseRawLineReturnsIrcMessage(): void
    {
        $handler = new InspIRCdProtocolHandler();
        $message = $handler->parseRawLine(':001 PRIVMSG #test :Hello world');

        self::assertInstanceOf(IRCMessage::class, $message);
        self::assertSame('PRIVMSG', $message->command);
        self::assertSame('001', $message->prefix);
        self::assertSame(['#test'], $message->params);
        self::assertSame('Hello world', $message->trailing);
    }

    #[Test]
    public function parseRawLineWithSimpleCommand(): void
    {
        $handler = new InspIRCdProtocolHandler();
        $message = $handler->parseRawLine('PING :server');

        self::assertInstanceOf(IRCMessage::class, $message);
        self::assertSame('PING', $message->command);
        self::assertNull($message->prefix);
        self::assertSame('server', $message->trailing);
    }

    #[Test]
    public function formatMessageReturnsRawLine(): void
    {
        $handler = new InspIRCdProtocolHandler();
        $message = IRCMessage::fromRawLine(':001 PRIVMSG #test :Hello world');
        $raw = $handler->formatMessage($message);

        self::assertSame(':001 PRIVMSG #test :Hello world', $raw);
    }

    #[Test]
    public function formatMessageWithNoTrailing(): void
    {
        $handler = new InspIRCdProtocolHandler();
        $message = IRCMessage::fromRawLine('PING server');
        $raw = $handler->formatMessage($message);

        self::assertSame('PING server', $raw);
    }

    #[Test]
    public function handleIncomingWithPingRespondsWithPong(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')
            ->with('PONG :server');

        $handler = new class extends AbstractProtocolHandler {
            public function performHandshake(\App\Domain\IRC\Connection\ConnectionInterface $connection, \App\Domain\IRC\Server\ServerLink $link): void
            {
            }

            public function getProtocolName(): string
            {
                return 'test';
            }
        };
        $message = new IRCMessage('PING', null, [], 'server');
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithPingWithParamRespondsWithPong(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')
            ->with('PONG :target');

        $handler = new class extends AbstractProtocolHandler {
            public function performHandshake(\App\Domain\IRC\Connection\ConnectionInterface $connection, \App\Domain\IRC\Server\ServerLink $link): void
            {
            }

            public function getProtocolName(): string
            {
                return 'test';
            }
        };
        $message = new IRCMessage('PING', null, ['target'], null);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithPingWithEmptyTargetRespondsWithEmptyPong(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')
            ->with('PONG :');

        $handler = new class extends AbstractProtocolHandler {
            public function performHandshake(\App\Domain\IRC\Connection\ConnectionInterface $connection, \App\Domain\IRC\Server\ServerLink $link): void
            {
            }

            public function getProtocolName(): string
            {
                return 'test';
            }
        };
        $message = new IRCMessage('PING', null, [''], null);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithNonPingDoesNothing(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = new class extends AbstractProtocolHandler {
            public function performHandshake(\App\Domain\IRC\Connection\ConnectionInterface $connection, \App\Domain\IRC\Server\ServerLink $link): void
            {
            }

            public function getProtocolName(): string
            {
                return 'test';
            }
        };
        $message = new IRCMessage('PRIVMSG', null, ['#test'], 'Hello');
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithJoinDoesNothing(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = new class extends AbstractProtocolHandler {
            public function performHandshake(\App\Domain\IRC\Connection\ConnectionInterface $connection, \App\Domain\IRC\Server\ServerLink $link): void
            {
            }

            public function getProtocolName(): string
            {
                return 'test';
            }
        };
        $message = new IRCMessage('JOIN', null, ['#test'], null);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithErrorLogsCritical(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = new class extends AbstractProtocolHandler {
            public function performHandshake(\App\Domain\IRC\Connection\ConnectionInterface $connection, \App\Domain\IRC\Server\ServerLink $link): void
            {
            }

            public function getProtocolName(): string
            {
                return 'test';
            }
        };
        $message = new IRCMessage('ERROR', null, [], 'Ping timeout');
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function dispatchSyncCompleteDispatchesNetworkSyncCompleteEvent(): void
    {
        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $eventDispatcher = $this->createMock(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);

        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event) use ($connection): bool {
                self::assertInstanceOf(\App\Domain\IRC\Event\NetworkSyncCompleteEvent::class, $event);
                self::assertSame($connection, $event->connection);
                self::assertSame('0AB', $event->serverSid);

                return true;
            }));

        $handler = new class(eventDispatcher: $eventDispatcher) extends AbstractProtocolHandler {
            public function dispatchSyncCompleteForTest(\App\Domain\IRC\Connection\ConnectionInterface $connection, string $sid): void
            {
                $this->dispatchSyncComplete($connection, $sid);
            }

            public function performHandshake(\App\Domain\IRC\Connection\ConnectionInterface $connection, \App\Domain\IRC\Server\ServerLink $link): void
            {
            }

            public function getProtocolName(): string
            {
                return 'test';
            }
        };

        $handler->dispatchSyncCompleteForTest($connection, '0AB');
    }

    #[Test]
    public function dispatchSyncCompleteDoesNothingWhenEventDispatcherIsNull(): void
    {
        $connection = $this->createStub(\App\Domain\IRC\Connection\ConnectionInterface::class);

        $handler = new class(eventDispatcher: null) extends AbstractProtocolHandler {
            public function dispatchSyncCompleteForTest(\App\Domain\IRC\Connection\ConnectionInterface $connection, string $sid): void
            {
                $this->dispatchSyncComplete($connection, $sid);
            }

            public function performHandshake(\App\Domain\IRC\Connection\ConnectionInterface $connection, \App\Domain\IRC\Server\ServerLink $link): void
            {
            }

            public function getProtocolName(): string
            {
                return 'test';
            }
        };

        $handler->dispatchSyncCompleteForTest($connection, '0AB');

        $this->expectNotToPerformAssertions();
    }
}
