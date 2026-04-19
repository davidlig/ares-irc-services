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
                '+ix',
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

    #[Test]
    public function handleUidWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('UID', '001', ['uuid', 'ts', 'nick'], null));
    }

    #[Test]
    public function handleFjoinDispatchesFjoinReceivedEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'FJOIN',
            null,
            ['#test', '1704067200', '+nt'],
            'o,abc123:0',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertSame('#test', $captured->channelName->value);
        self::assertSame(1704067200, $captured->timestamp);
        self::assertSame('+nt', $captured->modeStr);
        self::assertCount(1, $captured->members);
        self::assertSame('abc123', $captured->members[0]['uid']->value);
    }

    #[Test]
    public function handleFjoinWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test'], ''));
    }

    #[Test]
    public function handlePartDispatchesPartReceivedEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('PART', 'abc123', ['#test'], 'Bye'));

        self::assertInstanceOf(\App\Domain\IRC\Event\PartReceivedEvent::class, $captured);
        self::assertSame('abc123', $captured->sourceId);
        self::assertSame('#test', $captured->channelName->value);
        self::assertSame('Bye', $captured->reason);
    }

    #[Test]
    public function handleKickDispatchesKickReceivedEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('KICK', 'abc123', ['#chan', 'def456'], 'Kicked'));

        self::assertInstanceOf(\App\Domain\IRC\Event\KickReceivedEvent::class, $captured);
        self::assertSame('#chan', $captured->channelName->value);
        self::assertSame('def456', $captured->targetId);
        self::assertSame('Kicked', $captured->reason);
    }

    #[Test]
    public function handleFmodeDispatchesFmodeReceivedEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FMODE', null, ['#chan', '1704067200', '+nt'], null));

        self::assertInstanceOf(\App\Domain\IRC\Event\FmodeReceivedEvent::class, $captured);
        self::assertSame('#chan', $captured->channelName->value);
        self::assertSame('+nt', $captured->modeStr);
    }

    #[Test]
    public function handleLmodeDispatchesLmodeReceivedEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('LMODE', null, ['#chan', '1704067200', 'b', '*!*@bad.host'], null));

        self::assertInstanceOf(\App\Domain\IRC\Event\LmodeReceivedEvent::class, $captured);
        self::assertSame('#chan', $captured->channelName->value);
        self::assertSame('b', $captured->modeChar);
        self::assertSame(['*!*@bad.host'], $captured->params);
    }

    #[Test]
    public function handleFtopicDispatchesFtopicReceivedEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FTOPIC', null, ['#test', '1704067200'], 'Welcome'));

        self::assertInstanceOf(\App\Domain\IRC\Event\FtopicReceivedEvent::class, $captured);
        self::assertSame('#test', $captured->channelName->value);
        self::assertSame('Welcome', $captured->topic);
    }

    #[Test]
    public function handleNickWithEmptyPrefixDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('NICK', null, ['NewNick'], null));
    }

    #[Test]
    public function handleQuitWithEmptyPrefixDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('QUIT', null, [], 'Bye'));
    }

    #[Test]
    public function handleSquitWithEmptyServerSidDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('SQUIT', null, [], 'Split'));
    }

    #[Test]
    public function handleUidWith1206FormatDispatchesUserJoinedNetworkEvent(): void
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
                '+ix',
                'extra',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);
    }

    #[Test]
    public function handleUidWithInvalidValuesLogsWarningAndDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'UID',
            '001',
            [
                'abc123',
                '1234567890',
                '',
                'host.name',
                'cloak.host',
                'realuser',
                'displayuser',
                '127.0.0.1',
                '1234567890',
                '+ix',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);
    }

    #[Test]
    public function handleUidWithEmptyIpReturnsEmptyIpBase64(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

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
                '',
                '1234567890',
                '+i',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(UserJoinedNetworkEvent::class, $captured);
        self::assertSame('', $captured->user->ipBase64);
    }

    #[Test]
    public function handleUidWithAsteriskIpReturnsAsterisk(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

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
                '*',
                '1234567890',
                '+i',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(UserJoinedNetworkEvent::class, $captured);
        self::assertSame('*', $captured->user->ipBase64);
    }

    #[Test]
    public function handleFjoinWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['invalid', '1704067200', '+nt'], 'o,abc123:0'));
    }

    #[Test]
    public function handleFjoinWithMalformedEntrySkipsEntry(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200', '+nt'], 'malformed o,abc123:0'));

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(1, $captured->members);
    }

    #[Test]
    public function handleFjoinWithEmptyEntrySkipsEntry(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200', '+nt'], '  o,abc123:0'));

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(1, $captured->members);
    }

    #[Test]
    public function handleFjoinWithInvalidUidSkipsEntry(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200', '+nt'], 'o,:0'));

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(0, $captured->members);
    }

    #[Test]
    public function handlePartWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('PART', 'abc123', ['invalid'], 'Bye'));
    }

    #[Test]
    public function handlePartWithEmptyChannelDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('PART', 'abc123', [''], 'Bye'));
    }

    #[Test]
    public function handleKickWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('KICK', 'abc123', ['invalid', 'def456'], 'Kicked'));
    }

    #[Test]
    public function handleFmodeWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FMODE', null, ['#chan', '1704067200'], null));
    }

    #[Test]
    public function handleFmodeWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FMODE', null, ['invalid', '1704067200', '+nt'], null));
    }

    #[Test]
    public function handleLmodeWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('LMODE', null, ['#chan', '1704067200'], null));
    }

    #[Test]
    public function handleLmodeWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('LMODE', null, ['invalid', '1704067200', 'b', '*!*@bad.host'], null));
    }

    #[Test]
    public function handleFtopicWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FTOPIC', null, ['#test'], null));
    }

    #[Test]
    public function handleFtopicWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FTOPIC', null, ['invalid', '1704067200'], 'Topic'));
    }

    #[Test]
    public function handleUidWithIpv6AddressEncodesToBase64(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

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
                '::1',
                '1234567890',
                '+i',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(UserJoinedNetworkEvent::class, $captured);
        self::assertSame('AAAAAAAAAAAAAAAAAAAAAQ==', $captured->user->ipBase64);
    }

    #[Test]
    public function handleFjoinWithMultipleMembersWithDifferentPrefixes(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'FJOIN',
            null,
            ['#test', '1704067200', '+nt'],
            'o,abc123:0 v,def456:0 h,ghi789:0', // op, voice, halfop
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(3, $captured->members);
        self::assertSame('abc123', $captured->members[0]['uid']->value);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::Op, $captured->members[0]['role']);
        self::assertSame('def456', $captured->members[1]['uid']->value);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::Voice, $captured->members[1]['role']);
        self::assertSame('ghi789', $captured->members[2]['uid']->value);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::HalfOp, $captured->members[2]['role']);
    }

    #[Test]
    public function handleFjoinWithNoValidMembersDispatchesEmptyMembers(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $message = new IRCMessage(
            'FJOIN',
            null,
            ['#test', '1704067200', '+nt'],
            'malformed entry with no uid', // no valid entries
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(0, $captured->members);
    }

    #[Test]
    public function handleLmodeWithTrailingParamIncludesItInParams(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('LMODE', null, ['#chan', '1704067200', 'b', '*!*@host'], 'extra'));

        self::assertInstanceOf(\App\Domain\IRC\Event\LmodeReceivedEvent::class, $captured);
        self::assertSame(['*!*@host', 'extra'], $captured->params);
    }

    #[Test]
    public function handleNickWithEmptyNewNickDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('NICK', 'abc123', [''], null));
    }

    #[Test]
    public function handleUidWithInvalidIpFallbackToBase64EncodingRawString(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

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
                'not-a-valid-ip',
                '1234567890',
                '+i',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(UserJoinedNetworkEvent::class, $captured);
        self::assertSame(base64_encode('not-a-valid-ip'), $captured->user->ipBase64);
    }

    #[Test]
    public function handleUidWithIpv4AddressEncodesToBase64(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

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
                '192.168.1.1',
                '1234567890',
                '+i',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(UserJoinedNetworkEvent::class, $captured);
        self::assertSame(base64_encode(inet_pton('192.168.1.1')), $captured->user->ipBase64);
    }

    #[Test]
    public function handleFjoinWithEntryWhereColonBeforeCommaSkipsEntry(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200', '+nt'], ':0 o,abc123:0'));

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(1, $captured->members);
        self::assertSame('abc123', $captured->members[0]['uid']->value);
    }

    #[Test]
    public function handleFjoinWithEntryMissingColonSkipsEntry(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200', '+nt'], 'o,abc123 o,def456:0'));

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(1, $captured->members);
        self::assertSame('def456', $captured->members[0]['uid']->value);
    }

    #[Test]
    public function handleFjoinWithEntryMissingCommaSkipsEntry(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200', '+nt'], 'abc123:0'));

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(0, $captured->members);
    }

    #[Test]
    public function handleFjoinWithNoneRoleMemberHasEmptyPrefixLetters(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200', '+nt'], ',abc123:0'));

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(1, $captured->members);
        self::assertSame('abc123', $captured->members[0]['uid']->value);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::None, $captured->members[0]['role']);
        self::assertSame([], $captured->members[0]['prefixLetters']);
    }

    #[Test]
    public function handleKickWithEmptyTargetIdDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('KICK', 'abc123', ['#chan', ''], 'Kicked'));
    }

    #[Test]
    public function handlePartWithEmptySourceDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('PART', null, ['#test'], 'Bye'));
    }

    #[Test]
    public function handleUidWithNullTrailingUsesEmptyRealName(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function ($event) use (&$captured) {
                $captured = $event;

                return $event;
            });

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
                '+i',
            ],
            null,
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(UserJoinedNetworkEvent::class, $captured);
        self::assertSame('', $captured->user->realName);
    }

    #[Test]
    public function handleFtopicWithNullTopicDispatchesEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FTOPIC', null, ['#test', '1704067200'], null));

        self::assertInstanceOf(\App\Domain\IRC\Event\FtopicReceivedEvent::class, $captured);
        self::assertNull($captured->topic);
    }

    #[Test]
    public function handleUidReadsDisplayedUserAsIdent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

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
                '+i',
            ],
            'Real Name',
        );
        $adapter->handleMessage($message);

        self::assertInstanceOf(UserJoinedNetworkEvent::class, $captured);
        self::assertSame('abc123', $captured->user->uid->value);
        self::assertSame('InspNick', $captured->user->getNick()->value);
        self::assertSame('displayuser', $captured->user->ident->value);
    }

    #[Test]
    public function handleFmodeWithTwoParamsOnlyDoesNotDispatch(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FMODE', null, ['#chan', '1704067200'], null));
    }

    #[Test]
    public function handleFjoinWithTwoParamsOnlyDoesNotDispatch(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200'], null));
    }

    #[Test]
    public function handleFjoinWithEmptyEntriesInBuffer(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FJOIN', null, ['#test', '1704067200', '+nt'], '   o,abc123:0    o,def456:0   '));

        self::assertInstanceOf(\App\Domain\IRC\Event\FjoinReceivedEvent::class, $captured);
        self::assertCount(2, $captured->members);
        self::assertSame('abc123', $captured->members[0]['uid']->value);
        self::assertSame('def456', $captured->members[1]['uid']->value);
    }
}
