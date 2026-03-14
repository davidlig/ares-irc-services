<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network\Adapter;

use App\Domain\IRC\Event\NickChangeReceivedEvent;
use App\Domain\IRC\Event\QuitReceivedEvent;
use App\Domain\IRC\Event\ServerDelinkedEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Infrastructure\IRC\Network\Adapter\UnrealIRCdNetworkStateAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(UnrealIRCdNetworkStateAdapter::class)]
final class UnrealIRCdNetworkStateAdapterTest extends TestCase
{
    #[Test]
    public function getSupportedProtocolReturnsUnreal(): void
    {
        $adapter = new UnrealIRCdNetworkStateAdapter(
            $this->createStub(EventDispatcherInterface::class),
        );

        self::assertSame('unreal', $adapter->getSupportedProtocol());
    }

    #[Test]
    public function handleMessageWithUnknownCommandDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('UNKNOWN', null, [], null));
    }

    #[Test]
    public function handleUidDispatchesUserJoinedNetworkEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof UserJoinedNetworkEvent
                    && '001ABC' === $event->user->uid->value
                    && 'TestNick' === $event->user->getNick()->value));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'UID',
            '001',
            [
                'TestNick',
                '1',
                '1234567890',
                'ident',
                'host.name',
                '001ABC',
                '0',
                '',
                '',
                'cloak',
                'aXB4',
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
                    && '001ABC' === $event->sourceId
                    && 'NewNick' === $event->newNickStr));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('NICK', '001ABC', ['NewNick'], null));
    }

    #[Test]
    public function handleQuitDispatchesQuitReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof QuitReceivedEvent
                    && '001ABC' === $event->sourceId
                    && 'Leaving' === $event->reason));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('QUIT', '001ABC', [], 'Leaving'));
    }

    #[Test]
    public function handleSquitDispatchesServerDelinkedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof ServerDelinkedEvent
                    && '002' === $event->serverSid
                    && 'Split' === $event->reason));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['002'], 'Split'));
    }
}
