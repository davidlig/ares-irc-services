<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\PublishedEvent\ChannelIrcopOnlyUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelPendingDeletionEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRestoredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSuspendedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\In\Event\IrcopsDebugChannelProtectionSubscriber;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\OperatorOnlyChannelControl;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\ChannelSettingsChangedEvent;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IrcopsDebugChannelProtectionSubscriber::class)]
final class IrcopsDebugChannelProtectionSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = IrcopsDebugChannelProtectionSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(UserJoinedChannelEvent::class, $events);
        self::assertSame(['onUserJoined', 15], $events[UserJoinedChannelEvent::class]);
        self::assertArrayHasKey(NetworkSyncCompleteEvent::class, $events);
        self::assertSame(['onSyncComplete', 15], $events[NetworkSyncCompleteEvent::class]);
        self::assertArrayHasKey(ChannelIrcopOnlyUpdatedEvent::class, $events);
        self::assertArrayHasKey(ChannelSettingsChangedEvent::class, $events);
    }

    #[Test]
    public function subscriberDoesNothingWhenDebugChannelIsNull(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(debugChannel: null);

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberDoesNothingWhenDebugChannelIsEmpty(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(debugChannel: '');

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberDoesNothingWhenChannelIsNotDebugChannel(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#other');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberDoesNothingWhenUserNotFound(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberAllowsChanServToJoin(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $sender = new SenderView('UID001', 'ChanServ', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            chanservNick: 'ChanServ',
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID001', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberAllowsIdentifiedRootToJoin(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $sender = new SenderView('UID1', 'RootUser', 'i', 'h', 'c', 'ip', true, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('RootUser', 42, true, false)))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity));

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            authorization: $authorization,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberKicksNotIdentifiedRootUser(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('kickFromChannel');

        $sender = new SenderView('UID1', 'RootUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('RootUser', null, false, false)))
            ->willReturn(AuthorizationDecision::denied());

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            authorization: $authorization,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberAllowsIdentifiedIrcopToJoin(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        // SenderView: uid, nick, ident, hostname, cloakedHost, ipBase64, isIdentified, isOper, serverSid, displayHost, modes
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'encoded', true, true, 'SID1', 'c', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);

        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('OperUser', 42, true, true)))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus));

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            authorization: $authorization,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberKicksNonIrcopUser(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('kickFromChannel')
            ->with('#ircops', 'UID1', 'debug_channel.kick_reason');

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findAccountByNick')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $logger = $this->createStub(LoggerInterface::class);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            translator: $translator,
            logger: $logger,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberKicksWithCorrectLanguage(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('kickFromChannel')
            ->with('#ircops', 'UID1', 'No estás autorizado para entrar en este canal.');

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findAccountByNick')->willReturn(new NickAccountData(42, 'NormalUser', 'es'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('debug_channel.kick_reason', [], 'chanserv', 'es')
            ->willReturn('No estás autorizado para entrar en este canal.');

        $logger = $this->createStub(LoggerInterface::class);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            translator: $translator,
            logger: $logger,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberKicksIdentifiedUserWithNoRegisteredNick(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('kickFromChannel');

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', true, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(null);
        $nickAccounts->method('findAccountByNick')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberKicksIdentifiedUserWithNoIrcopRecord(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('kickFromChannel');

        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'encoded', true, false, 'SID1', 'c', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $nickAccounts->method('findAccountByNick')->willReturn(new NickAccountData(42, 'OperUser', 'en'));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    #[Test]
    public function subscriberKicksNotIdentifiedUser(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('kickFromChannel');

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $event = $this->createEvent('UID1', '#ircops');
        $subscriber->onUserJoined($event);
    }

    // === onSyncComplete tests ===

    #[Test]
    public function onSyncCompleteDoesNothingWhenDebugChannelIsNull(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            debugChannel: null,
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenDebugChannelIsEmpty(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            debugChannel: '',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenChannelNotFound(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteDoesNothingWhenChannelHasNoMembers(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $channelView = new ChannelView(
            name: '#ircops',
            modes: '+nt',
            topic: null,
            memberCount: 0,
            members: [],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteKicksNonIrcopUsersDuringBurst(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::exactly(2))
            ->method('kickFromChannel')
            ->with(self::stringContains('#ircops'), self::stringContains('UID'), 'debug_channel.kick_reason');

        $channelView = new ChannelView(
            name: '#ircops',
            modes: '+nt',
            topic: null,
            memberCount: 3,
            members: [
                ['uid' => 'UID1', 'roleLetter' => ''],
                ['uid' => 'UID2', 'roleLetter' => ''],
                ['uid' => 'UID3', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        // UID1 y UID2 son usuarios normales, UID3 es ChanServ
        $sender1 = new SenderView('UID1', 'NormalUser1', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');
        $sender2 = new SenderView('UID2', 'NormalUser2', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');
        $sender3 = new SenderView('UID3', 'ChanServ', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')
            ->willReturnCallback(static fn (string $uid) => match ($uid) {
                'UID1' => $sender1,
                'UID2' => $sender2,
                'UID3' => $sender3,
                default => null,
            });

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findAccountByNick')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('debug_channel.kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            translator: $translator,
            chanservNick: 'ChanServ',
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteAllowsIdentifiedRootUserDuringBurst(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $channelView = new ChannelView(
            name: '#ircops',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => 'UID1', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $sender = new SenderView('UID1', 'RootUser', 'i', 'h', 'c', 'ip', true, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('RootUser', 42, true, false)))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity));

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            authorization: $authorization,
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteKicksNotIdentifiedRootUserDuringBurst(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('kickFromChannel');

        $channelView = new ChannelView(
            name: '#ircops',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => 'UID1', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $sender = new SenderView('UID1', 'RootUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('RootUser', null, false, false)))
            ->willReturn(AuthorizationDecision::denied());

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            authorization: $authorization,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteAllowsIdentifiedIrcopDuringBurst(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $channelView = new ChannelView(
            name: '#ircops',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => 'UID1', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'encoded', true, true, 'SID1', 'c', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);

        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('OperUser', 42, true, true)))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus));

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            authorization: $authorization,
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteKicksWithCorrectLanguageDuringBurst(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())
            ->method('kickFromChannel')
            ->with('#ircops', 'UID1', 'No estás autorizado para entrar en este canal.');

        $channelView = new ChannelView(
            name: '#ircops',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => 'UID1', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findAccountByNick')->willReturn(new NickAccountData(42, 'NormalUser', 'es'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('debug_channel.kick_reason', [], 'chanserv', 'es')
            ->willReturn('No estás autorizado para entrar en este canal.');

        $logger = $this->createStub(LoggerInterface::class);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            nickAccounts: $nickAccounts,
            translator: $translator,
            logger: $logger,
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsMemberWithEmptyUid(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $channelView = new ChannelView(
            name: '#ircops',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => '', 'roleLetter' => ''], // Empty UID should be skipped
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    #[Test]
    public function onSyncCompleteSkipsMemberNotFoundInNetwork(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('kickFromChannel');

        $channelView = new ChannelView(
            name: '#ircops',
            modes: '+nt',
            topic: null,
            memberCount: 1,
            members: [
                ['uid' => 'UID1', 'roleLetter' => ''],
            ],
        );

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            debugChannel: '#ircops',
        );

        $event = new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001');
        $subscriber->onSyncComplete($event);
    }

    private function createEvent(string $uid, string $channelName): UserJoinedChannelEvent
    {
        return new UserJoinedChannelEvent(
            new Uid($uid),
            new ChannelName($channelName),
            ChannelMemberRole::None,
        );
    }

    #[Test]
    public function enabledRegisteredChannelActivatesAndKicksExistingUnauthorizedMember(): void
    {
        $control = $this->createMock(OperatorOnlyChannelControl::class);
        $control->expects(self::once())->method('activate')->with('#ops');
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())->method('kickFromChannel')->with('#ops', 'U1', 'denied');
        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn(new ChannelView('#ops', '+O', null, 1, [['uid' => 'U1', 'roleLetter' => '']]));
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturn(new SenderView('U1', 'Guest', 'i', 'h', 'c', 'ip', false, false, 'SID', 'h', 'i'));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('denied');

        $this->createSubscriber(channelActions: $actions, channelLookup: $lookup, userLookup: $users, translator: $translator, debugChannel: null, registeredChannels: $this->query($this->projection()), operatorOnlyControl: $control)
            ->onIrcopOnlyUpdated(new ChannelIrcopOnlyUpdatedEvent('#ops'));
    }

    #[Test]
    public function protectedRegisteredChannelKicksUnauthorizedJoin(): void
    {
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::once())->method('kickFromChannel')->with('#ops', 'U1', 'denied');
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturn(new SenderView('U1', 'Guest', 'i', 'h', 'c', 'ip', false, false, 'SID', 'h', 'i'));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('denied');

        $this->createSubscriber(channelActions: $actions, userLookup: $users, translator: $translator, debugChannel: null, registeredChannels: $this->query($this->projection()))
            ->onUserJoined($this->createEvent('U1', '#ops'));
    }

    #[Test]
    public function syncActivatesOnlyActiveRegisteredChannels(): void
    {
        $control = $this->createMock(OperatorOnlyChannelControl::class);
        $control->expects(self::once())->method('activate')->with('#ops');
        $query = $this->createStub(ChannelProjectionQuery::class);
        $query->method('all')->willReturn([$this->projection(), $this->projection(suspended: true), $this->projection(pendingDeletion: true), $this->projection(ircopOnly: false)]);

        $this->createSubscriber(debugChannel: null, registeredChannels: $query, operatorOnlyControl: $control)
            ->onSyncComplete(new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001'));
    }

    #[Test]
    public function disabledOrBlockedChannelDeactivates(): void
    {
        $control = $this->createMock(OperatorOnlyChannelControl::class);
        $control->expects(self::exactly(2))->method('deactivate')->with('#ops');
        $query = $this->createStub(ChannelProjectionQuery::class);
        $query->method('findByName')->willReturnOnConsecutiveCalls($this->projection(ircopOnly: false), $this->projection(suspended: true));
        $subscriber = $this->createSubscriber(registeredChannels: $query, operatorOnlyControl: $control);
        $subscriber->onIrcopOnlyUpdated(new ChannelIrcopOnlyUpdatedEvent('#ops'));
        $subscriber->onIrcopOnlyUpdated(new ChannelIrcopOnlyUpdatedEvent('#ops'));
    }

    #[Test]
    public function lifecyclePausesAndRestoresProtection(): void
    {
        $control = $this->createMock(OperatorOnlyChannelControl::class);
        $control->expects(self::exactly(3))->method('deactivate')->with('#ops');
        $control->expects(self::exactly(2))->method('activate')->with('#ops');
        $control->expects(self::once())->method('ensureMode')->with('#ops');
        $subscriber = $this->createSubscriber(registeredChannels: $this->query($this->projection()), operatorOnlyControl: $control);
        $now = new DateTimeImmutable('2026-09-26T00:00:00+00:00');
        $subscriber->onChannelSuspended(new ChannelSuspendedEvent(1, '#ops', '#ops', 'reason', null, null, 'Oper', null, '*', '*', $now));
        $subscriber->onChannelPendingDeletion(new ChannelPendingDeletionEvent(1, '#ops', '#ops', 'Oper', $now));
        $subscriber->onChannelUnsuspended(new ChannelUnsuspendedEvent(1, '#ops', '#ops', 'Oper', null, '*', '*', $now));
        $subscriber->onChannelRestored(new ChannelRestoredEvent(1, '#ops', '#ops', 'Oper', $now));
        $subscriber->onSettingsChanged(new ChannelSettingsChangedEvent('#ops'));
        $subscriber->onChannelDrop(new ChannelDropEvent(1, '#ops', '#ops', 'drop', $now, true));
    }

    private function projection(bool $ircopOnly = true, bool $suspended = false, bool $pendingDeletion = false): ChannelProjection
    {
        return new ChannelProjection(1, '#ops', 1, null, false, '', [], false, false, null, $suspended, $pendingDeletion, null, false, $ircopOnly);
    }

    private function query(ChannelProjection $projection): ChannelProjectionQuery
    {
        $query = $this->createStub(ChannelProjectionQuery::class);
        $query->method('findByName')->willReturn($projection);

        return $query;
    }

    private function createSubscriber(
        ?ChannelServiceActionsPort $channelActions = null,
        ?ChannelLookupPort $channelLookup = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?NickAccountQuery $nickAccounts = null,
        ?OperatorAuthorizationQuery $authorization = null,
        ?TranslatorInterface $translator = null,
        ?LoggerInterface $logger = null,
        ?string $debugChannel = '#ircops',
        string $chanservNick = 'ChanServ',
        ?ChannelProjectionQuery $registeredChannels = null,
        ?OperatorOnlyChannelControl $operatorOnlyControl = null,
    ): IrcopsDebugChannelProtectionSubscriber {
        return new IrcopsDebugChannelProtectionSubscriber(
            channelActions: $channelActions ?? $this->createStub(ChannelServiceActionsPort::class),
            channelLookup: $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            userLookup: $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            nickAccounts: $nickAccounts ?? $this->createStub(NickAccountQuery::class),
            authorization: $authorization ?? $this->deniedAuthorization(),
            translator: $translator ?? $this->createStub(TranslatorInterface::class),
            defaultLanguage: 'en',
            chanservNick: $chanservNick,
            debugChannel: $debugChannel,
            logger: $logger ?? $this->createStub(LoggerInterface::class),
            registeredChannels: $registeredChannels,
            operatorOnlyControl: $operatorOnlyControl,
        );
    }

    private function deniedAuthorization(): OperatorAuthorizationQuery
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('ircOperator')->willReturn(AuthorizationDecision::denied());

        return $authorization;
    }
}
