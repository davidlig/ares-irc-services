<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceCommandListenerInterface;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Application\Services\Antiflood\AntifloodRegistry;
use App\Application\Services\Antiflood\ClientKeyResolver;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\IRC\Message\MessageDirection;
use App\Infrastructure\IRC\ServiceBridge\AntifloodSubscriber;
use App\Infrastructure\IRC\ServiceBridge\ServiceCommandGateway;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AntifloodSubscriber::class)]
final class AntifloodSubscriberTest extends TestCase
{
    private AntifloodRegistry $registry;

    private ClientKeyResolver $clientKeyResolver;

    private ServiceCommandGateway $gateway;

    private NetworkUserLookupPort $userLookup;

    private SendNoticePort $sendNotice;

    private UserMessageTypeResolverInterface $messageTypeResolver;

    private OperServNotifierInterface $notifier;

    private RootUserRegistry $rootRegistry;

    private TranslatorInterface $translator;

    private ServiceCommandListenerInterface $nickservListener;

    protected function setUp(): void
    {
        $this->registry = new AntifloodRegistry();
        $this->clientKeyResolver = new ClientKeyResolver();
        $this->userLookup = $this->createStub(NetworkUserLookupPort::class);
        $this->sendNotice = $this->createStub(SendNoticePort::class);
        $this->messageTypeResolver = $this->createStub(UserMessageTypeResolverInterface::class);
        $this->notifier = $this->createStub(OperServNotifierInterface::class);
        $this->rootRegistry = new RootUserRegistry('');
        $this->translator = $this->createStub(TranslatorInterface::class);
        $this->nickservListener = $this->createStub(ServiceCommandListenerInterface::class);
        $this->nickservListener->method('getServiceName')->willReturn('NickServ');
        $this->nickservListener->method('getServiceUid')->willReturn('002AAAAAA');

        $this->gateway = new ServiceCommandGateway(
            listeners: [$this->nickservListener],
            logger: new NullLogger(),
        );
    }

    private function createSubscriber(int $maxMessages = 5, int $windowSeconds = 10, int $cooldownSeconds = 60, ?string $debugChannel = null, string $rootUsers = ''): AntifloodSubscriber
    {
        $rootRegistry = '' !== $rootUsers ? new RootUserRegistry($rootUsers) : $this->rootRegistry;

        return new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $this->userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->notifier,
            $rootRegistry,
            $this->translator,
            'en',
            $debugChannel,
            $maxMessages,
            $windowSeconds,
            $cooldownSeconds,
            new NullLogger(),
        );
    }

    private function createSender(bool $isOper = false, string $ipBase64 = 'AQID', string $nick = 'TestUser'): SenderView
    {
        return new SenderView(
            uid: '002AAAAAB',
            nick: $nick,
            ident: 'test',
            hostname: 'host.example.com',
            cloakedHost: 'cloak.example.com',
            ipBase64: $ipBase64,
            isOper: $isOper,
        );
    }

    private function createMessage(string $command, string $target, string $text, string $sourceId = '002CCCCCC'): MessageReceivedEvent
    {
        $message = new IRCMessage(
            command: $command,
            prefix: $sourceId,
            params: [$target],
            trailing: $text,
            direction: MessageDirection::Incoming,
        );

        return new MessageReceivedEvent($message);
    }

    #[Test]
    public function onMessageDoesNothingWhenMaxMessagesIsZero(): void
    {
        $subscriber = $this->createSubscriber(maxMessages: 0);

        $event = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresNonPrivmsgCommands(): void
    {
        $subscriber = $this->createSubscriber();

        $event = $this->createMessage('JOIN', '#test', 'hello');
        $subscriber->onMessage($event);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresUnknownTarget(): void
    {
        $subscriber = $this->createSubscriber();

        $event = $this->createMessage('PRIVMSG', 'UnknownBot', 'HELP');
        $subscriber->onMessage($event);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresUnknownSender(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->willReturn(null);
        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->notifier,
            $this->rootRegistry,
            $this->translator,
            'en',
            null,
            5,
            10,
            60,
            new NullLogger(),
        );

        $event = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageAllowsCommandWhenUnderLimit(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->willReturn($this->createSender());
        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->notifier,
            $this->rootRegistry,
            $this->translator,
            'en',
            null,
            3,
            3600,
            60,
            new NullLogger(),
        );

        $event = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageBlocksCommandWhenOverLimit(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::exactly(3))->method('findByUid')->willReturn($this->createSender());

        $messageTypeResolver = $this->createMock(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->expects(self::once())->method('resolveByNick')->with('TestUser')->willReturn('NOTICE');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnCallback(
            static fn (string $id) => 'antiflood.debug_channel' === $id ? 'debug msg' : 'Slow down!',
        );

        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::once())->method('sendMessage')->with('002AAAAAA', '002AAAAAB', 'Slow down!', 'NOTICE');

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $sendNotice,
            $messageTypeResolver,
            $notifier,
            $this->rootRegistry,
            $translator,
            'en',
            '#ircops',
            2,
            3600,
            60,
            new NullLogger(),
        );

        $event1 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event1);
        self::assertFalse($event1->isPropagationStopped());

        $event2 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event2);
        self::assertFalse($event2->isPropagationStopped());

        $event3 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event3);
        self::assertTrue($event3->isPropagationStopped());
    }

    #[Test]
    public function onMessageSendsNoticeOnlyOnceDuringLockout(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::exactly(3))->method('findByUid')->willReturn($this->createSender());

        $messageTypeResolver = $this->createMock(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->expects(self::once())->method('resolveByNick')->with('TestUser')->willReturn('NOTICE');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnCallback(
            static fn (string $id) => 'antiflood.debug_channel' === $id ? 'debug msg' : 'Slow down!',
        );

        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::once())->method('sendMessage');

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $sendNotice,
            $messageTypeResolver,
            $notifier,
            $this->rootRegistry,
            $translator,
            'en',
            '#ircops',
            1,
            3600,
            60,
            new NullLogger(),
        );

        $event1 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event1);
        self::assertFalse($event1->isPropagationStopped());

        $event2 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event2);
        self::assertTrue($event2->isPropagationStopped());

        $event3 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event3);
        self::assertTrue($event3->isPropagationStopped());
    }

    #[Test]
    public function onMessageExemptsIrcops(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::exactly(2))->method('findByUid')->willReturn($this->createSender(isOper: true));

        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->notifier,
            $this->rootRegistry,
            $this->translator,
            'en',
            null,
            1,
            3600,
            60,
            new NullLogger(),
        );

        $event1 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event1);
        self::assertFalse($event1->isPropagationStopped());

        $event2 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event2);
        self::assertFalse($event2->isPropagationStopped());
    }

    #[Test]
    public function onMessageExemptsRootAdmins(): void
    {
        $rootRegistry = new RootUserRegistry('TestUser');
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::exactly(2))->method('findByUid')->willReturn($this->createSender(isOper: false, nick: 'TestUser'));

        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->notifier,
            $rootRegistry,
            $this->translator,
            'en',
            null,
            1,
            3600,
            60,
            new NullLogger(),
        );

        $event1 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event1);
        self::assertFalse($event1->isPropagationStopped());

        $event2 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event2);
        self::assertFalse($event2->isPropagationStopped());
    }

    #[Test]
    public function onMessageIrcopNotBlockedBySharedIpWithRegularUser(): void
    {
        $regularUser = $this->createSender(isOper: false, ipBase64: 'c2hhcmVk');
        $ircopUser = $this->createSender(isOper: true, ipBase64: 'c2hhcmVk');

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::exactly(4))->method('findByUid')
            ->willReturnOnConsecutiveCalls($regularUser, $regularUser, $ircopUser, $ircopUser);

        $messageTypeResolver = $this->createMock(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->expects(self::once())->method('resolveByNick')->willReturn('NOTICE');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))->method('trans')->willReturnCallback(
            static fn (string $id) => 'antiflood.debug_channel' === $id ? 'debug msg' : 'Slow down!',
        );

        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::once())->method('sendMessage');

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $sendNotice,
            $messageTypeResolver,
            $notifier,
            $this->rootRegistry,
            $translator,
            'en',
            '#ircops',
            1,
            3600,
            60,
            new NullLogger(),
        );

        $event1 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event1);
        self::assertFalse($event1->isPropagationStopped());

        $event2 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event2);
        self::assertTrue($event2->isPropagationStopped());

        $event3 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event3);
        self::assertFalse($event3->isPropagationStopped());

        $event4 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event4);
        self::assertFalse($event4->isPropagationStopped());
    }

    #[Test]
    public function onMessageSkipsDebugChannelWhenNull(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::exactly(2))->method('findByUid')->willReturn($this->createSender());

        $messageTypeResolver = $this->createMock(UserMessageTypeResolverInterface::class);
        $messageTypeResolver->expects(self::once())->method('resolveByNick')->willReturn('NOTICE');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->willReturn('Slow down!');

        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::once())->method('sendMessage');

        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $sendNotice,
            $messageTypeResolver,
            $notifier,
            $this->rootRegistry,
            $translator,
            'en',
            null,
            1,
            3600,
            60,
            new NullLogger(),
        );

        $event1 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event1);
        self::assertFalse($event1->isPropagationStopped());

        $event2 = $this->createMessage('PRIVMSG', 'NickServ', 'HELP');
        $subscriber->onMessage($event2);
        self::assertTrue($event2->isPropagationStopped());
    }

    #[Test]
    public function onMessageHandlesSqueryCommand(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->willReturn($this->createSender());

        $subscriber = new AntifloodSubscriber(
            $this->registry,
            $this->clientKeyResolver,
            $this->gateway,
            $userLookup,
            $this->sendNotice,
            $this->messageTypeResolver,
            $this->notifier,
            $this->rootRegistry,
            $this->translator,
            'en',
            null,
            5,
            10,
            60,
            new NullLogger(),
        );

        $event = $this->createMessage('SQUERY', 'NickServ', 'HELP');
        $subscriber->onMessage($event);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresEmptyTarget(): void
    {
        $subscriber = $this->createSubscriber();

        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '002CCCCCC',
            params: [],
            trailing: 'HELP',
            direction: MessageDirection::Incoming,
        );
        $event = new MessageReceivedEvent($message);
        $subscriber->onMessage($event);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresEmptySourceId(): void
    {
        $subscriber = $this->createSubscriber();

        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: '',
            params: ['NickServ'],
            trailing: 'HELP',
            direction: MessageDirection::Incoming,
        );
        $event = new MessageReceivedEvent($message);
        $subscriber->onMessage($event);

        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectPriority(): void
    {
        $events = AntifloodSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(MessageReceivedEvent::class, $events);
        self::assertSame(['onMessage', 10], $events[MessageReceivedEvent::class]);
    }
}
