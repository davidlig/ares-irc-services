<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network\Adapter;

use App\Domain\IRC\Event\ServerDelinkedEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Infrastructure\IRC\Network\Adapter\InspIRCdNetworkStateAdapter;
use App\Infrastructure\IRC\Network\Event\ChannelJoinReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelKickReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelListModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelPartReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelTopicReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserMetadataReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserNickChangeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserQuitReceivedEvent;
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
    public function handleNickDispatchesUserNickChangeReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof UserNickChangeReceivedEvent
                    && 'abc123' === $event->sourceId
                    && 'NewNick' === $event->newNickStr));

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('NICK', 'abc123', ['NewNick'], null));
    }

    #[Test]
    public function handleQuitDispatchesUserQuitReceivedEvent(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn ($event): bool => $event instanceof UserQuitReceivedEvent
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelPartReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelKickReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelModeReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelListModeReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelTopicReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelListModeReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelTopicReceivedEvent::class, $captured);
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

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
        self::assertCount(2, $captured->members);
        self::assertSame('abc123', $captured->members[0]['uid']->value);
        self::assertSame('def456', $captured->members[1]['uid']->value);
    }

    #[Test]
    public function handleModeDispatchesChannelModeReceivedEventForChannel(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['#chan', '+o', '001ABC'], null));

        self::assertInstanceOf(ChannelModeReceivedEvent::class, $captured);
        self::assertSame('#chan', $captured->channelName->value);
        self::assertSame('+o', $captured->modeStr);
        self::assertSame(['001ABC'], $captured->modeParams);
    }

    #[Test]
    public function handleModeWithTrailingParamIncludesItInModeParams(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['#chan', '+b'], '*!*@bad.host'));

        self::assertInstanceOf(ChannelModeReceivedEvent::class, $captured);
        self::assertSame('#chan', $captured->channelName->value);
        self::assertSame(['*!*@bad.host'], $captured->modeParams);
    }

    #[Test]
    public function handleModeDispatchesUserModeReceivedEventForUidTarget(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', '001ABC', ['001ABCDEF', '+i'], null));

        self::assertInstanceOf(UserModeReceivedEvent::class, $captured);
        self::assertSame('001ABC', $captured->sourceId);
        self::assertSame('+i', $captured->modeStr);
    }

    #[Test]
    public function handleModeWithEmptyTargetDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['', '+i'], null));
    }

    #[Test]
    public function handleModeWithEmptyModeStrDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['#chan', ''], null));
    }

    #[Test]
    public function handleModeWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['invalid', '+o', '001ABC'], null));
    }

    #[Test]
    public function handleMetadataDispatchesUserMetadataReceivedEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('METADATA', null, ['001ABCDEF', 'accountname'], 'TestAccount'));

        self::assertInstanceOf(UserMetadataReceivedEvent::class, $captured);
        self::assertSame('001ABCDEF', $captured->targetUid);
        self::assertSame('accountname', $captured->key);
        self::assertSame('TestAccount', $captured->value);
    }

    #[Test]
    public function handleMetadataWithEmptyTargetDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('METADATA', null, ['', 'accountname'], 'TestAccount'));
    }

    #[Test]
    public function handleMetadataWithEmptyKeyDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('METADATA', null, ['001ABCDEF', ''], 'TestAccount'));
    }

    #[Test]
    public function handleMetadataWithNonUidTargetDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('METADATA', null, ['#channel', 'accountname'], 'TestAccount'));
    }

    #[Test]
    public function handleOpertypeLogsInfoAndDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('OPERTYPE', '001ABC', [], 'NetAdmin'));
    }

    #[Test]
    public function handleOpertypeWithEmptySourceIdDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('OPERTYPE', null, [], 'NetAdmin'));
    }

    #[Test]
    public function handleFmodeWithTrailingParamIncludesItInModeParams(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FMODE', null, ['#chan', '1704067200', '+b'], '*!*@bad.host'));

        self::assertInstanceOf(ChannelModeReceivedEvent::class, $captured);
        self::assertSame('#chan', $captured->channelName->value);
        self::assertSame(['*!*@bad.host'], $captured->modeParams);
    }

    #[Test]
    public function handleFtopicWithSetterNickFromParams(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FTOPIC', null, ['#test', '1704067200', '1704067200', 'SetterNick'], 'Welcome'));

        self::assertInstanceOf(ChannelTopicReceivedEvent::class, $captured);
        self::assertSame('#test', $captured->channelName->value);
        self::assertSame('Welcome', $captured->topic);
        self::assertSame('SetterNick', $captured->setterNick);
    }

    #[Test]
    public function handleFtopicExtractsNickFromHostmask(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FTOPIC', null, ['#ares', '1776797549', '1776800479', 'davidlig!ares@ares.virtual'], 'Canal oficial'));

        self::assertInstanceOf(ChannelTopicReceivedEvent::class, $captured);
        self::assertSame('davidlig', $captured->setterNick);
        self::assertNull($captured->sourceUid);
    }

    #[Test]
    public function handleFtopicIgnoresServerHostnameAsSetter(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FTOPIC', null, ['#ares', '1776886641', '1776887120', 'ares-services.davidlig.net'], 'Canal oficial'));

        self::assertInstanceOf(ChannelTopicReceivedEvent::class, $captured);
        self::assertNull($captured->setterNick);
        self::assertNull($captured->sourceUid);
    }

    #[Test]
    public function handleFtopicSetsSourceUidFromPrefixWhenNoSetterParam(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('FTOPIC', '994AAAGUW', ['#ares', '1776889998', '1776891167'], 'Canal oficial del desarrollo'));

        self::assertInstanceOf(ChannelTopicReceivedEvent::class, $captured);
        self::assertNull($captured->setterNick);
        self::assertSame('994AAAGUW', $captured->sourceUid);
    }

    #[Test]
    public function handleModeWithInvalidChannelNameDispatchesNothingForChannelMode(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('MODE', null, ['#', '+o', '001ABC'], null));
    }

    #[Test]
    public function handleIjoinDispatchesChannelJoinReceivedEvent(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['#opers', '7'], null));

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
        self::assertSame('#opers', $captured->channelName->value);
        self::assertSame(0, $captured->timestamp);
        self::assertCount(1, $captured->members);
        self::assertSame('994AAAAAQ', $captured->members[0]['uid']->value);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::Op, $captured->members[0]['role']);
        self::assertSame(['o', 'h', 'v'], $captured->members[0]['prefixLetters']);
    }

    #[Test]
    public function handleIjoinWithCreationTsParsesTimestamp(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['#opers', '4', '1704067200'], null));

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
        self::assertSame('#opers', $captured->channelName->value);
        self::assertSame(1704067200, $captured->timestamp);
        self::assertCount(1, $captured->members);
        self::assertSame('994AAAAAQ', $captured->members[0]['uid']->value);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::Op, $captured->members[0]['role']);
    }

    #[Test]
    public function handleIjoinWithZeroModeHintIsNoneRole(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['#test', '0'], null));

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::None, $captured->members[0]['role']);
        self::assertSame([], $captured->members[0]['prefixLetters']);
    }

    #[Test]
    public function handleIjoinWithVoiceModeHint(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['#test', '1'], null));

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::Voice, $captured->members[0]['role']);
        self::assertSame(['v'], $captured->members[0]['prefixLetters']);
    }

    #[Test]
    public function handleIjoinWithOwnerModeHint(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['#test', '16'], null));

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::Owner, $captured->members[0]['role']);
        self::assertSame(['q'], $captured->members[0]['prefixLetters']);
    }

    #[Test]
    public function handleIjoinWithAdminModeHint(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['#test', '8'], null));

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::Admin, $captured->members[0]['role']);
        self::assertSame(['a'], $captured->members[0]['prefixLetters']);
    }

    #[Test]
    public function handleIjoinWithHalfopModeHint(): void
    {
        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['#test', '2'], null));

        self::assertInstanceOf(ChannelJoinReceivedEvent::class, $captured);
        self::assertSame(\App\Domain\IRC\Network\ChannelMemberRole::HalfOp, $captured->members[0]['role']);
        self::assertSame(['h'], $captured->members[0]['prefixLetters']);
    }

    #[Test]
    public function handleIjoinWithTooFewParamsDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['#test'], null));
    }

    #[Test]
    public function handleIjoinWithEmptyUidDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '', ['#test', '0'], null));
    }

    #[Test]
    public function handleIjoinWithInvalidChannelNameDispatchesNothing(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $adapter = new InspIRCdNetworkStateAdapter($eventDispatcher);
        $adapter->handleMessage(new IRCMessage('IJOIN', '994AAAAAQ', ['invalid', '0'], null));
    }
}
