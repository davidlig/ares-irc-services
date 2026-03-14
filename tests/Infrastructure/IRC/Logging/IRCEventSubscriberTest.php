<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Logging;

use App\Domain\IRC\Event\ConnectionEstablishedEvent;
use App\Domain\IRC\Event\ConnectionLostEvent;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Server\ServerLink;
use App\Domain\IRC\ValueObject\Hostname;
use App\Domain\IRC\ValueObject\LinkPassword;
use App\Domain\IRC\ValueObject\Port;
use App\Domain\IRC\ValueObject\ServerName;
use App\Infrastructure\IRC\Logging\IRCEventSubscriber;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(IRCEventSubscriber::class)]
final class IRCEventSubscriberTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    private IRCEventSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
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

        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                'Server link established.',
                self::callback(static fn (array $context): bool => $context['server'] === (string) $serverLink->serverName
                        && $context['host'] === (string) $serverLink->host
                        && $context['port'] === $serverLink->port->value
                        && $context['tls'] === $serverLink->useTls
                        && isset($context['occurred'])),
            );

        $this->subscriber->onConnectionEstablished($event);
    }

    #[Test]
    public function onConnectionLostLogsWarning(): void
    {
        $serverLink = $this->createServerLink();
        $event = new ConnectionLostEvent($serverLink, 'Connection reset');

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Server link lost.',
                self::callback(static fn (array $context): bool => $context['server'] === (string) $serverLink->serverName
                        && 'Connection reset' === $context['reason']
                        && isset($context['occurred'])),
            );

        $this->subscriber->onConnectionLost($event);
    }

    #[Test]
    public function onConnectionLostWithNullReason(): void
    {
        $serverLink = $this->createServerLink();
        $event = new ConnectionLostEvent($serverLink, null);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'Server link lost.',
                self::callback(static fn (array $context): bool => 'unknown' === $context['reason']),
            );

        $this->subscriber->onConnectionLost($event);
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

        $this->logger->expects(self::once())
            ->method('debug')
            ->with(
                '< PING',
                self::callback(static fn (array $context): bool => 'irc.example.com' === $context['prefix']
                        && $context['params'] === ['irc.example.com']
                        && null === $context['trailing']),
            );

        $this->subscriber->onMessageReceived($event);
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

        $this->logger->expects(self::once())
            ->method('debug')
            ->with(
                '< PRIVMSG',
                self::callback(static fn (array $context): bool => 'IDENTIFY ******' === $context['trailing']
                        && str_contains($context['raw'], 'IDENTIFY ******')
                        && !str_contains($context['raw'], 'mysecretpassword')),
            );

        $this->subscriber->onMessageReceived($event);
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
