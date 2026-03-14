<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network\Adapter;

use App\Domain\IRC\Event\NickChangeReceivedEvent;
use App\Domain\IRC\Event\QuitReceivedEvent;
use App\Domain\IRC\Event\ServerDelinkedEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Infrastructure\IRC\Network\Adapter\InspIRCdNetworkStateAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(InspIRCdNetworkStateAdapter::class)]
final class InspIRCdNetworkStateAdapterTest extends TestCase
{
    #[Test]
    public function getSupportedProtocolReturnsInspircd(): void
    {
        $adapter = new InspIRCdNetworkStateAdapter(
            $this->createStub(EventDispatcherInterface::class),
        );

        self::assertSame('inspircd', $adapter->getSupportedProtocol());
    }

    #[Test]
    public function handleMessageWithUnknownCommandDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('UNKNOWN', null, [], null));
    }

    #[Test]
    public function handleUidDispatchesUserJoinedNetworkEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof UserJoinedNetworkEvent
                    && 'abc123' === $event->user->uid->value
                    && 'InspNick' === $event->user->getNick()->value));

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'UID',
            '001',
            [
                'abc123',
                '1234567890',
                'InspNick',
                'host.name',
                'cloak.host',
                'realuser',
                'displayuser',
                '127.0.0.1',
                '1234567890',
                '',
                '',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);
    }

    #[Test]
    public function handleNickDispatchesNickChangeReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof NickChangeReceivedEvent
                    && 'abc123' === $event->sourceId
                    && 'NewNick' === $event->newNickStr));

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('NICK', 'abc123', ['NewNick'], null));
    }

    #[Test]
    public function handleQuitDispatchesQuitReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof QuitReceivedEvent
                    && 'abc123' === $event->sourceId
                    && 'Leaving' === $event->reason));

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('QUIT', 'abc123', [], 'Leaving'));
    }

    #[Test]
    public function handleSquitDispatchesServerDelinkedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof ServerDelinkedEvent
                    && '002' === $event->serverSid
                    && 'Split' === $event->reason));

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['002'], 'Split'));
    }
}
