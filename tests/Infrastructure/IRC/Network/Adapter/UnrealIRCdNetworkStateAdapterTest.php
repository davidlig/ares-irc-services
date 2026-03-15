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

    #[Test]
    public function handleUidWithInvalidNickLogsWarningAndDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'UID',
            '001',
            [
                '',
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
    public function handleSjoinWithListModeBAddsBanMask(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#test'],
            '&*!*@bad.host 001ABC123',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(FjoinReceivedEvent::class, $captured);
        self::assertSame(['*!*@bad.host'], $captured->listModes['b']);
    }

    #[Test]
    public function handleSjoinWithListModeEAddsExemptMask(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#test'],
            '"*!*@exempt.host 001ABC123',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(FjoinReceivedEvent::class, $captured);
        self::assertSame(['*!*@exempt.host'], $captured->listModes['e']);
    }

    #[Test]
    public function handleSjoinWithListModeIAddsInviteExceptionMask(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#test'],
            "'*!*@invite.host 001ABC123",
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(FjoinReceivedEvent::class, $captured);
        self::assertSame(['*!*@invite.host'], $captured->listModes['I']);
    }

    #[Test]
    public function handleSjoinWithExtPrefixStripsExt(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#test'],
            '<ext:001ABC>',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(FjoinReceivedEvent::class, $captured);
        self::assertCount(0, $captured->members);
    }

    #[Test]
    public function handleSjoinWithInvalidChannelNameLogsWarningAndDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', 'invalid'],
            '001ABC123',
        );
        $adapter->handleMessage($message);
    }

    #[Test]
    public function handlePartWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('PART', '001ABC', ['invalid'], 'Bye'));
    }

    #[Test]
    public function handleKickWithEmptyTargetIdDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('KICK', '001ABC', ['#chan', ''], 'Kicked'));
    }

    #[Test]
    public function handleKickWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('KICK', '001ABC', ['invalid', '002DEF'], 'Kicked'));
    }

    #[Test]
    public function handleUmode2WithEmptySourceIdDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('UMODE2', null, ['+i'], null));
    }

    #[Test]
    public function handleSethostWithEmptySourceIdDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SETHOST', null, [], 'new.host'));
    }

    #[Test]
    public function handleMdWithClientAccountLogsDebug(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MD', null, ['client', '001ABC', 'account'], 'TestAccount'));
    }

    #[Test]
    public function handleTopicWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('TOPIC', null, ['invalid'], 'Topic'));
    }

    #[Test]
    public function handleTopicWithSetterContainingBangExtractsNick(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('TOPIC', null, ['#test', 'SetterNick!user@host'], 'New topic'));

        self::assertInstanceOf(FtopicReceivedEvent::class, $captured);
        self::assertSame('SetterNick', $captured->setterNick);
    }

    #[Test]
    public function handleModeWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['#chan'], null));
    }

    #[Test]
    public function handleModeWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['invalid', '+o', '001ABC'], null));
    }

    #[Test]
    public function handleUidWithInvalidUidValueLogsWarningAndDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'UID',
            '001',
            [
                'ValidNick',
                '1',
                '1234567890',
                'ident',
                'host.name',
                '', // empty UID - invalid
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
    public function handleUidWithInvalidIdentLogsWarningAndDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'UID',
            '001',
            [
                'ValidNick',
                '1',
                '1234567890',
                '', // empty ident - invalid
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
    public function handleSjoinWithEmptyBufferDispatchesEventWithEmptyMembers(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof FjoinReceivedEvent
                    && '#test' === $event->channelName->value
                    && [] === $event->members));

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#test'],
            '   ',
        );
        $adapter->handleMessage($message);
    }

    #[Test]
    public function handleSjoinWithOnlyListModesDispatchesEventWithEmptyMembers(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#test'],
            '&*!*@ban1 &*!*@ban2',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(FjoinReceivedEvent::class, $captured);
        self::assertCount(0, $captured->members);
        self::assertSame(['*!*@ban1', '*!*@ban2'], $captured->listModes['b']);
    }

    #[Test]
    public function handleSjoinWithMultipleMembersAndMixedEntries(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'SJOIN',
            null,
            ['1704067200', '#test'],
            '@001AAA +001BBB 001CCC',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(FjoinReceivedEvent::class, $captured);
        self::assertCount(3, $captured->members);
    }

    #[Test]
    public function handleModeWithTrailingParamIncludesItInModeParams(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['#chan', '+b'], '*!*@bad.host'));

        self::assertInstanceOf(ModeReceivedEvent::class, $captured);
        self::assertSame(['*!*@bad.host'], $captured->modeParams);
    }

    #[Test]
    public function handleModeWithBothParamsAndTrailingIncludesBoth(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['#chan', '+k', 'secret'], 'extra'));

        self::assertInstanceOf(ModeReceivedEvent::class, $captured);
        self::assertSame(['secret', 'extra'], $captured->modeParams);
    }

    #[Test]
    public function handleNickWithEmptyNewNickDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('NICK', '001ABC', [''], null));
    }

    #[Test]
    public function handleMdWithNonClientTargetDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MD', null, ['channel', '#test', 'topic'], 'New topic'));
    }

    #[Test]
    public function handleMdWithEmptyUidDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MD', null, ['client', '', 'account'], 'TestAccount'));
    }

    #[Test]
    public function handleMdWithNonAccountKeyDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MD', null, ['client', '001ABC', 'certfp'], 'somevalue'));
    }

    #[Test]
    public function handleUmode2WithModeStrFromTrailing(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('UMODE2', '001ABC', [], '+iwx'));

        self::assertInstanceOf(Umode2ReceivedEvent::class, $captured);
        self::assertSame('001ABC', $captured->sourceId);
        self::assertSame('+iwx', $captured->modeStr);
    }

    #[Test]
    public function handleUmode2WithEmptyModeStrDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('UMODE2', '001ABC', [''], null));
    }

    #[Test]
    public function handleTopicWithEmptyChannelDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('TOPIC', null, [''], 'Topic'));
    }

    #[Test]
    public function handleTopicWithSetterNickWithoutBang(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('TOPIC', null, ['#test', 'SetterNick'], 'New topic'));

        self::assertInstanceOf(FtopicReceivedEvent::class, $captured);
        self::assertSame('SetterNick', $captured->setterNick);
    }

    #[Test]
    public function handleTopicWithEmptySetterParamSetsNullSetterNick(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('TOPIC', null, ['#test'], 'New topic'));

        self::assertInstanceOf(FtopicReceivedEvent::class, $captured);
        self::assertNull($captured->setterNick);
    }

    #[Test]
    public function handleSjoinWithExtPrefixLoggedInUserParsesUid(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SJOIN', null, ['1704067200', '#test'], '<ext:001ABC>'));

        self::assertInstanceOf(FjoinReceivedEvent::class, $captured);
        self::assertCount(0, $captured->members);
    }

    #[Test]
    public function handleSjoinWithNoModesParams(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SJOIN', null, ['1704067200', '#test'], '001ABC123'));

        self::assertInstanceOf(FjoinReceivedEvent::class, $captured);
        self::assertSame('', $captured->modeStr);
        self::assertSame([], $captured->modeParams);
    }

    #[Test]
    public function handleSethostWithEmptyNewHostDispatchesEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new UnrealIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SETHOST', '001ABC', [], ''));

        self::assertInstanceOf(SethostReceivedEvent::class, $captured);
        self::assertSame('001ABC', $captured->sourceId);
        self::assertSame('', $captured->newHost);
    }
}
