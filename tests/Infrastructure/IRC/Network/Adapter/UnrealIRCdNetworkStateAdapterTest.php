<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network\Adapter;

use App\Domain\IRC\Event\FjoinReceivedEvent;
use App\Domain\IRC\Event\FtopicReceivedEvent;
use App\Domain\IRC\Event\KickReceivedEvent;
use App\Domain\IRC\Event\ModeReceivedEvent;
use App\Domain\IRC\Event\NickChangeReceivedEvent;
use App\Domain\IRC\Event\PartReceivedEvent;
use App\Domain\IRC\Event\QuitReceivedEvent;
use App\Domain\IRC\Event\ServerDelinkedEvent;
use App\Domain\IRC\Event\SethostReceivedEvent;
use App\Domain\IRC\Event\Umode2ReceivedEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Infrastructure\IRC\Network\Adapter\UnrealIRCdNetworkStateAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;

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

    #[Test]
    public function handleUidWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('UID', '001', ['Nick', '1', '123'], null));
    }

    #[Test]
    public function handleNickWithEmptyPrefixDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('NICK', null, ['NewNick'], null));
    }

    #[Test]
    public function handleQuitWithEmptyPrefixDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('QUIT', null, [], 'Bye'));
    }

    #[Test]
    public function handleSquitWithEmptyServerSidDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SQUIT', null, [], 'Split'));
    }

    #[Test]
    public function handleSjoinDispatchesFjoinReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static function ($event): bool {
                if (!$event instanceof FjoinReceivedEvent) {
                    return false;
                }

                return '#test' === $event->channelName->value
                    && 1704067200 === $event->timestamp
                    && 1 === count($event->members)
                    && '001ABC123' === $event->members[0]['uid']->value;
            }));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#test'],
            '@001ABC123',
        );
        $adapter->handleMessage($message);
    }

    #[Test]
    public function handleSjoinWithModesAndModeParamsDispatchesFjoinReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof FjoinReceivedEvent
                    && '#chan' === $event->channelName->value
                    && '+lk' === $event->modeStr
                    && $event->modeParams === ['500', 'secretkey']));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#chan', '+lk', '500', 'secretkey'],
            '+001XYZ',
        );
        $adapter->handleMessage($message);
    }

    #[Test]
    public function handleSjoinWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SJOIN', null, ['1704067200'], ''));
    }

    #[Test]
    public function handlePartDispatchesPartReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof PartReceivedEvent
                    && '001ABC' === $event->sourceId
                    && '#test' === $event->channelName->value
                    && 'Bye' === $event->reason));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('PART', '001ABC', ['#test'], 'Bye'));
    }

    #[Test]
    public function handlePartWithEmptySourceDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('PART', null, ['#test'], 'Bye'));
    }

    #[Test]
    public function handleKickDispatchesKickReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof KickReceivedEvent
                    && '#chan' === $event->channelName->value
                    && '002DEF' === $event->targetId
                    && 'Kicked' === $event->reason));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('KICK', '001ABC', ['#chan', '002DEF'], 'Kicked'));
    }

    #[Test]
    public function handleUmode2DispatchesUmode2ReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof Umode2ReceivedEvent
                    && '001ABC' === $event->sourceId
                    && '+i' === $event->modeStr));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('UMODE2', '001ABC', ['+i'], null));
    }

    #[Test]
    public function handleSethostDispatchesSethostReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof SethostReceivedEvent
                    && '001ABC' === $event->sourceId
                    && 'new.host.name' === $event->newHost));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SETHOST', '001ABC', [], 'new.host.name'));
    }

    #[Test]
    public function handleTopicDispatchesFtopicReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof FtopicReceivedEvent
                    && '#test' === $event->channelName->value
                    && 'Welcome' === $event->topic
                    && 'SetterNick' === $event->setterNick));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('TOPIC', null, ['#test', 'SetterNick'], 'Welcome'));
    }

    #[Test]
    public function handleModeDispatchesModeReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static function ($event): bool {
                if (!$event instanceof ModeReceivedEvent) {
                    return false;
                }

                return '#chan' === $event->channelName->value
                    && '+o' === $event->modeStr
                    && $event->modeParams === ['001ABC'];
            }));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['#chan', '+o', '001ABC'], null));
    }
}
