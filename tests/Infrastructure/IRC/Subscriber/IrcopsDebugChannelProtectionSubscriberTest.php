<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Subscriber\IrcopsDebugChannelProtectionSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
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

        $sender = new SenderView('UID001', 'ChanServ', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i', '');

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

        $rootRegistry = new RootUserRegistry('RootUser');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            rootRegistry: $rootRegistry,
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

        $rootRegistry = new RootUserRegistry('RootUser');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            rootRegistry: $rootRegistry,
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

        $nick = RegisteredNick::createPending('OperUser', 'hash', 'test@test.com', 'en', new DateTimeImmutable('+1 day'));
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setAccessible(true);
        $nickIdProp->setValue($nick, 42);

        $role = OperRole::create('OPER', 'Oper role');
        $ircop = OperIrcop::create(42, $role, 1, null);

        // SenderView: uid, nick, ident, hostname, cloakedHost, ipBase64, isIdentified, isOper, serverSid, displayHost, modes
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'encoded', true, false, 'SID1', 'c', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickRepo: $nickRepo,
            ircopRepo: $ircopRepo,
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

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i', '');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $logger = $this->createStub(LoggerInterface::class);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickRepo: $nickRepo,
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

        $nick = RegisteredNick::createPending('NormalUser', 'hash', 'test@test.com', 'es', new DateTimeImmutable('+1 day'));
        $nick->activate();

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i', '');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('debug_channel.kick_reason', [], 'chanserv', 'es')
            ->willReturn('No estás autorizado para entrar en este canal.');

        $logger = $this->createStub(LoggerInterface::class);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickRepo: $nickRepo,
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

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', true, false, 'SID1', 'h', 'i', '');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickRepo: $nickRepo,
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

        $nick = RegisteredNick::createPending('OperUser', 'hash', 'test@test.com', 'en', new DateTimeImmutable('+1 day'));
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setAccessible(true);
        $nickIdProp->setValue($nick, 42);

        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'encoded', true, false, 'SID1', 'c', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            userLookup: $userLookup,
            nickRepo: $nickRepo,
            ircopRepo: $ircopRepo,
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

        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'i', '');

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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('debug_channel.kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            nickRepo: $nickRepo,
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

        $rootRegistry = new RootUserRegistry('RootUser');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            rootRegistry: $rootRegistry,
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

        $rootRegistry = new RootUserRegistry('RootUser');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('kick_reason');

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            rootRegistry: $rootRegistry,
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

        $nick = RegisteredNick::createPending('OperUser', 'hash', 'test@test.com', 'en', new DateTimeImmutable('+1 day'));
        $nick->activate();
        $nickRefl = new ReflectionClass($nick);
        $nickIdProp = $nickRefl->getProperty('id');
        $nickIdProp->setAccessible(true);
        $nickIdProp->setValue($nick, 42);

        $role = OperRole::create('OPER', 'Oper role');
        $ircop = OperIrcop::create(42, $role, 1, null);

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

        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'encoded', true, false, 'SID1', 'c', 'i');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($sender);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $subscriber = $this->createSubscriber(
            channelActions: $channelActions,
            channelLookup: $channelLookup,
            userLookup: $userLookup,
            nickRepo: $nickRepo,
            ircopRepo: $ircopRepo,
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

        $nick = RegisteredNick::createPending('NormalUser', 'hash', 'test@test.com', 'es', new DateTimeImmutable('+1 day'));
        $nick->activate();

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

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);

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
            nickRepo: $nickRepo,
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
                ['uid' => ''], // Empty UID should be skipped
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

    private function createSubscriber(
        ?ChannelServiceActionsPort $channelActions = null,
        ?ChannelLookupPort $channelLookup = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        ?RootUserRegistry $rootRegistry = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?TranslatorInterface $translator = null,
        ?LoggerInterface $logger = null,
        ?string $debugChannel = '#ircops',
        string $chanservNick = 'ChanServ',
    ): IrcopsDebugChannelProtectionSubscriber {
        return new IrcopsDebugChannelProtectionSubscriber(
            channelActions: $channelActions ?? $this->createStub(ChannelServiceActionsPort::class),
            channelLookup: $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            userLookup: $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            ircopRepo: $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            rootRegistry: $rootRegistry ?? new RootUserRegistry(''),
            nickRepo: $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            translator: $translator ?? $this->createStub(TranslatorInterface::class),
            defaultLanguage: 'en',
            chanservNick: $chanservNick,
            debugChannel: $debugChannel,
            logger: $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
