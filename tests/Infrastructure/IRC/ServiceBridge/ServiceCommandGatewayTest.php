<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ServiceCommandListenerInterface;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Infrastructure\IRC\ServiceBridge\ServiceCommandGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;

#[CoversClass(ServiceCommandGateway::class)]
final class ServiceCommandGatewayTest extends TestCase
{
    private ServiceCommandListenerInterface&MockObject $nickservListener;

    private ServiceCommandListenerInterface&MockObject $chanservListener;

    private LoggerInterface&MockObject $logger;

    private ServiceCommandGateway $gateway;

    protected function setUp(): void
    {
        $this->nickservListener = $this->createMock(ServiceCommandListenerInterface::class);
        $this->nickservListener->method('getServiceName')->willReturn('NickServ');
        $this->nickservListener->method('getServiceUid')->willReturn('001NICK');

        $this->chanservListener = $this->createMock(ServiceCommandListenerInterface::class);
        $this->chanservListener->method('getServiceName')->willReturn('ChanServ');
        $this->chanservListener->method('getServiceUid')->willReturn('001CHAN');

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->gateway = new ServiceCommandGateway(
            listeners: [$this->nickservListener, $this->chanservListener],
            logger: $this->logger,
        );
    }

    #[Test]
    public function subscribesToMessageReceivedEvent(): void
    {
        self::assertSame(
            [MessageReceivedEvent::class => ['onMessage', 0]],
            ServiceCommandGateway::getSubscribedEvents(),
        );
    }

    #[Test]
    public function dispatchesCommandToCorrectListenerByNick(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '001ABCD',
            params: ['NickServ'],
            trailing: 'IDENTIFY password',
        );

        $this->nickservListener
            ->expects(self::once())
            ->method('onCommand')
            ->with('001ABCD', 'IDENTIFY password');

        $this->chanservListener
            ->expects(self::never())
            ->method('onCommand');

        $event = new MessageReceivedEvent($message);
        $this->gateway->onMessage($event);
    }

    #[Test]
    public function dispatchesCommandToCorrectListenerByUid(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '001ABCD',
            params: ['001CHAN'],
            trailing: 'OP #test User',
        );

        $this->nickservListener
            ->expects(self::never())
            ->method('onCommand');

        $this->chanservListener
            ->expects(self::once())
            ->method('onCommand')
            ->with('001ABCD', 'OP #test User');

        $event = new MessageReceivedEvent($message);
        $this->gateway->onMessage($event);
    }

    #[Test]
    public function ignoresNonPrivmsgCommands(): void
    {
        $message = new IRCMessage(
            command: 'NOTICE',
            prefix: '001ABCD',
            params: ['NickServ'],
            trailing: 'Hello',
        );

        $this->nickservListener
            ->expects(self::never())
            ->method('onCommand');

        $event = new MessageReceivedEvent($message);
        $this->gateway->onMessage($event);
    }

    #[Test]
    public function ignoresUnknownTarget(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '001ABCD',
            params: ['UnknownService'],
            trailing: 'HELP',
        );

        $this->nickservListener
            ->expects(self::never())
            ->method('onCommand');

        $this->chanservListener
            ->expects(self::never())
            ->method('onCommand');

        $event = new MessageReceivedEvent($message);
        $this->gateway->onMessage($event);
    }

    #[Test]
    public function handlesCaseInsensitiveTarget(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '001ABCD',
            params: ['NICKSERV'],
            trailing: 'HELP',
        );

        $this->nickservListener
            ->expects(self::once())
            ->method('onCommand')
            ->with('001ABCD', 'HELP');

        $event = new MessageReceivedEvent($message);
        $this->gateway->onMessage($event);
    }

    #[Test]
    public function ignoresMessageWithoutTarget(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '001ABCD',
            params: [],
            trailing: 'HELP',
        );

        $this->nickservListener
            ->expects(self::never())
            ->method('onCommand');

        $event = new MessageReceivedEvent($message);
        $this->gateway->onMessage($event);
    }

    #[Test]
    public function ignoresMessageWithoutPrefix(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: null,
            params: ['NickServ'],
            trailing: 'HELP',
        );

        $this->nickservListener
            ->expects(self::never())
            ->method('onCommand');

        $event = new MessageReceivedEvent($message);
        $this->gateway->onMessage($event);
    }

    #[Test]
    public function handlesEmptyTrailing(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '001ABCD',
            params: ['NickServ'],
            trailing: null,
        );

        $this->nickservListener
            ->expects(self::once())
            ->method('onCommand')
            ->with('001ABCD', '');

        $event = new MessageReceivedEvent($message);
        $this->gateway->onMessage($event);
    }

    #[Test]
    public function skipsNonListenerItemsInIterable(): void
    {
        $gateway = new ServiceCommandGateway(
            listeners: [$this->nickservListener, new stdClass(), $this->chanservListener],
            logger: $this->logger,
        );
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '001ABCD',
            params: ['NickServ'],
            trailing: 'HELP',
        );
        $this->nickservListener->expects(self::once())->method('onCommand')->with('001ABCD', 'HELP');
        $gateway->onMessage(new MessageReceivedEvent($message));
    }
}
