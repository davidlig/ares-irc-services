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

        $handler = new InspIRCdProtocolHandler();
        $message = new IRCMessage('PING', null, [], 'server');
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithPingWithParamRespondsWithPong(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')
            ->with('PONG :target');

        $handler = new InspIRCdProtocolHandler();
        $message = new IRCMessage('PING', null, ['target'], null);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithPingWithEmptyTargetRespondsWithEmptyPong(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::once())->method('writeLine')
            ->with('PONG :');

        $handler = new InspIRCdProtocolHandler();
        $message = new IRCMessage('PING', null, [''], null);
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithNonPingDoesNothing(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = new InspIRCdProtocolHandler();
        $message = new IRCMessage('PRIVMSG', null, ['#test'], 'Hello');
        $handler->handleIncoming($message, $connection);
    }

    #[Test]
    public function handleIncomingWithJoinDoesNothing(): void
    {
        $connection = $this->createMock(\App\Domain\IRC\Connection\ConnectionInterface::class);
        $connection->expects(self::never())->method('writeLine');

        $handler = new InspIRCdProtocolHandler();
        $message = new IRCMessage('JOIN', null, ['#test'], null);
        $handler->handleIncoming($message, $connection);
    }
}
