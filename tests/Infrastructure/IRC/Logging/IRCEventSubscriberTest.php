<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Logging;

use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Event\MessageReceivedEvent;
use App\Infrastructure\IRC\Logging\IRCEventSubscriber;
use App\Irc\Adapter\Protocol\IRCMessage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(IRCEventSubscriber::class)]
final class IRCEventSubscriberTest extends TestCase
{
    private LoggerInterface $logger;

    private TestHandler $handler;

    private IRCEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->handler = new TestHandler();
        $this->logger = new Logger('test', [$this->handler]);
        $this->subscriber = new IRCEventSubscriber($this->logger);
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = IRCEventSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(ConnectionEstablishedEvent::class, $events);
        self::assertArrayHasKey(ConnectionLostEvent::class, $events);
        self::assertArrayHasKey(MessageReceivedEvent::class, $events);
    }

    #[Test]
    public function onConnectionEstablishedLogsInfo(): void
    {
        $serverLink = $this->createServerLink();
        $event = new ConnectionEstablishedEvent($serverLink);

        $this->subscriber->onConnectionEstablished($event);

        $record = $this->record();
        self::assertSame('Server link established.', $record->message);
        self::assertSame((string) $serverLink->serverName, $record->context['server']);
        self::assertSame((string) $serverLink->host, $record->context['host']);
        self::assertSame($serverLink->port->value, $record->context['port']);
        self::assertSame($serverLink->useTls, $record->context['tls']);
        self::assertArrayHasKey('occurred', $record->context);
    }

    #[Test]
    public function onConnectionLostLogsWarning(): void
    {
        $serverLink = $this->createServerLink();
        $event = new ConnectionLostEvent($serverLink, 'Connection reset');

        $this->subscriber->onConnectionLost($event);

        $record = $this->record();
        self::assertSame('Server link lost.', $record->message);
        self::assertSame((string) $serverLink->serverName, $record->context['server']);
        self::assertSame('Connection reset', $record->context['reason']);
        self::assertArrayHasKey('occurred', $record->context);
    }

    #[Test]
    public function onConnectionLostWithNullReason(): void
    {
        $serverLink = $this->createServerLink();
        $event = new ConnectionLostEvent($serverLink, null);

        $this->subscriber->onConnectionLost($event);

        self::assertSame('unknown', $this->record()->context['reason']);
    }

    #[Test]
    public function onMessageReceivedLogsDebug(): void
    {
        $message = new IRCMessage(
            prefix: 'irc.example.com',
            command: 'PING',
            params: ['irc.example.com'],
            trailing: null,
        );
        $event = new MessageReceivedEvent($message);

        $this->subscriber->onMessageReceived($event);

        $record = $this->record();
        self::assertSame('< PING', $record->message);
        self::assertSame('irc.example.com', $record->context['prefix']);
        self::assertSame(['irc.example.com'], $record->context['params']);
        self::assertNull($record->context['trailing']);
    }

    #[Test]
    public function onMessageReceivedRedactsSensitiveNickServCommands(): void
    {
        $message = new IRCMessage(
            prefix: 'nick!user@host',
            command: 'PRIVMSG',
            params: ['NickServ'],
            trailing: 'IDENTIFY mysecretpassword',
        );
        $event = new MessageReceivedEvent($message);

        $this->subscriber->onMessageReceived($event);

        $record = $this->record();
        self::assertSame('IDENTIFY ******', $record->context['trailing']);
        self::assertIsString($record->context['raw']);
        self::assertStringContainsString('IDENTIFY ******', $record->context['raw']);
        self::assertStringNotContainsString('mysecretpassword', $record->context['raw']);
    }

    #[Test]
    public function onMessageReceivedRedactsSensitiveSqueryCommands(): void
    {
        $message = new IRCMessage(
            prefix: 'nick!user@host',
            command: 'SQUERY',
            params: ['NickServ'],
            trailing: 'VERIFY verification-token',
        );
        $event = new MessageReceivedEvent($message);

        $this->subscriber->onMessageReceived($event);

        $record = $this->record();
        self::assertSame('VERIFY ******', $record->context['trailing']);
        self::assertIsString($record->context['raw']);
        self::assertStringContainsString('VERIFY ******', $record->context['raw']);
        self::assertStringNotContainsString('verification-token', $record->context['raw']);
    }

    private function record(): LogRecord
    {
        $records = $this->handler->getRecords();
        self::assertCount(1, $records);
        $record = array_pop($records);

        return $record;
    }

    private function createServerLink(): ServerLink
    {
        return new ServerLink(
            new ServerName('irc.example.com'),
            new Hostname('192.168.1.1'),
            new Port(7000),
            new LinkPassword('secret'),
            'Test Server',
            true,
        );
    }
}
