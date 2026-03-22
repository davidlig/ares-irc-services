<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendCtcpPort;
use App\Application\Port\SenderView;
use App\Application\Port\SendNoticePort;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Message\IRCMessage;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\ServiceBridge\CtcpHandler;
use App\Infrastructure\IRC\ServiceBridge\CtcpVersionResponder;
use App\Infrastructure\NickServ\UserLanguageResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(CtcpHandler::class)]
final class CtcpHandlerTest extends TestCase
{
    private CtcpVersionResponder $versionResponder;

    private function createTranslator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                if ('ctcp.version.tribute' === $id) {
                    return "Para mi leal y eterno amigo,\nLlenaste mi casa de vida.";
                }

                return '';
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    private function createLanguageResolver(string $language = 'en'): UserLanguageResolver
    {
        return new UserLanguageResolver(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $language,
        );
    }

    private function createSenderView(string $uid): SenderView
    {
        return new SenderView(
            uid: $uid,
            nick: 'TestUser',
            ident: 'test',
            hostname: 'host.example',
            cloakedHost: 'cloak.example',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );
    }

    protected function setUp(): void
    {
        $this->versionResponder = new CtcpVersionResponder(
            $this->createTranslator(),
            $this->createLanguageResolver(),
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsCorrectEvent(): void
    {
        $events = CtcpHandler::getSubscribedEvents();

        self::assertArrayHasKey(MessageReceivedEvent::class, $events);
        self::assertSame(['onMessage', 10], $events[MessageReceivedEvent::class]);
    }

    #[Test]
    public function onMessageIgnoresNonPrivmsg(): void
    {
        $message = new IRCMessage(
            command: 'NOTICE',
            prefix: 'sender!user@host',
            params: ['NickServ'],
            trailing: "\x01VERSION\x01",
        );
        $event = new MessageReceivedEvent($message);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp->expects(self::never())->method('sendCtcpReply');
        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::never())->method('sendNotice');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            sendNotice: $sendNotice,
        );

        $handler->onMessage($event);
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresEmptyTrailing(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: 'sender!user@host',
            params: ['NickServ'],
            trailing: null,
        );
        $event = new MessageReceivedEvent($message);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp->expects(self::never())->method('sendCtcpReply');
        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::never())->method('sendNotice');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            sendNotice: $sendNotice,
        );

        $handler->onMessage($event);
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresEmptyPrefix(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: null,
            params: ['NickServ'],
            trailing: "\x01VERSION\x01",
        );
        $event = new MessageReceivedEvent($message);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp->expects(self::never())->method('sendCtcpReply');
        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::never())->method('sendNotice');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            sendNotice: $sendNotice,
        );

        $handler->onMessage($event);
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresNonCtcpMessage(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: 'sender!user@host',
            params: ['NickServ'],
            trailing: 'IDENTIFY password',
        );
        $event = new MessageReceivedEvent($message);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp->expects(self::never())->method('sendCtcpReply');
        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::never())->method('sendNotice');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            sendNotice: $sendNotice,
        );

        $handler->onMessage($event);
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresOtherCtcpCommands(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: 'sender!user@host',
            params: ['NickServ'],
            trailing: "\x01PING\x01",
        );
        $event = new MessageReceivedEvent($message);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp->expects(self::never())->method('sendCtcpReply');
        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::never())->method('sendNotice');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            sendNotice: $sendNotice,
        );

        $handler->onMessage($event);
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresCtcpTime(): void
    {
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: 'sender!user@host',
            params: ['NickServ'],
            trailing: "\x01TIME\x01",
        );
        $event = new MessageReceivedEvent($message);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp->expects(self::never())->method('sendCtcpReply');
        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::never())->method('sendNotice');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            sendNotice: $sendNotice,
        );

        $handler->onMessage($event);
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageRespondsToCtcpVersionAndStopsPropagation(): void
    {
        $senderUid = '001ABC';
        $sender = $this->createSenderView($senderUid);
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: $senderUid,
            params: ['NickServ'],
            trailing: "\x01VERSION\x01",
        );
        $event = new MessageReceivedEvent($message);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with($senderUid)->willReturn($sender);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp
            ->expects(self::once())
            ->method('sendCtcpReply')
            ->with('002AAAAAA', $senderUid, 'VERSION', 'Ares IRC Services v1.0');

        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice
            ->expects(self::atLeastOnce())
            ->method('sendMessage')
            ->with('002AAAAAA', $senderUid, self::anything(), 'NOTICE');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            sendNotice: $sendNotice,
            userLookup: $userLookup,
        );

        $handler->onMessage($event);
        self::assertTrue($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageRespondsToCtcpVersionLowercase(): void
    {
        $senderUid = '001ABC';
        $sender = $this->createSenderView($senderUid);
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: $senderUid,
            params: ['NickServ'],
            trailing: "\x01version\x01",
        );
        $event = new MessageReceivedEvent($message);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with($senderUid)->willReturn($sender);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp
            ->expects(self::once())
            ->method('sendCtcpReply')
            ->with('002AAAAAA', $senderUid, 'VERSION', 'Ares IRC Services v1.0');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            userLookup: $userLookup,
        );

        $handler->onMessage($event);
        self::assertTrue($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageUsesDefaultLanguageWhenUserNotFound(): void
    {
        $senderUid = '001ABC';
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: $senderUid,
            params: ['NickServ'],
            trailing: "\x01VERSION\x01",
        );
        $event = new MessageReceivedEvent($message);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with($senderUid)->willReturn(null);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp->expects(self::once())->method('sendCtcpReply')
            ->with('002AAAAAA', $senderUid, 'VERSION', 'Ares IRC Services v1.0');

        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::atLeastOnce())->method('sendMessage')
            ->with('002AAAAAA', $senderUid, self::anything(), 'NOTICE');

        $handler = $this->createHandler(
            sendCtcp: $sendCtcp,
            sendNotice: $sendNotice,
            userLookup: $userLookup,
        );

        $handler->onMessage($event);
        self::assertTrue($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageIgnoresUnknownTargetService(): void
    {
        $senderUid = '001ABC';
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: $senderUid,
            params: ['UnknownService'],
            trailing: "\x01VERSION\x01",
        );
        $event = new MessageReceivedEvent($message);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp->expects(self::never())->method('sendCtcpReply');
        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice->expects(self::never())->method('sendMessage');

        $handler = new CtcpHandler(
            $sendCtcp,
            $sendNotice,
            $this->versionResponder,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createLanguageResolver(),
            ['nickserv' => '002AAAAAA'],
        );

        $handler->onMessage($event);
        self::assertFalse($event->isPropagationStopped());
    }

    #[Test]
    public function onMessageRespondsToVersionWhenTargetIsServiceUid(): void
    {
        $senderUid = '001ABC';
        $serviceUid = '002AAAAAA';
        $sender = $this->createSenderView($senderUid);
        $message = new IRCMessage(
            command: 'PRIVMSG',
            prefix: $senderUid,
            params: [$serviceUid],
            trailing: "\x01VERSION\x01",
        );
        $event = new MessageReceivedEvent($message);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with($senderUid)->willReturn($sender);

        $sendCtcp = $this->createMock(SendCtcpPort::class);
        $sendCtcp
            ->expects(self::once())
            ->method('sendCtcpReply')
            ->with($serviceUid, $senderUid, 'VERSION', 'Ares IRC Services v1.0');

        $sendNotice = $this->createMock(SendNoticePort::class);
        $sendNotice
            ->expects(self::atLeastOnce())
            ->method('sendMessage')
            ->with($serviceUid, $senderUid, self::anything(), 'NOTICE');

        $handler = new CtcpHandler(
            $sendCtcp,
            $sendNotice,
            $this->versionResponder,
            $userLookup,
            $this->createLanguageResolver(),
            ['nickserv' => $serviceUid],
        );

        $handler->onMessage($event);
        self::assertTrue($event->isPropagationStopped());
    }

    private function createHandler(
        ?SendCtcpPort $sendCtcp = null,
        ?SendNoticePort $sendNotice = null,
        ?NetworkUserLookupPort $userLookup = null,
    ): CtcpHandler {
        return new CtcpHandler(
            $sendCtcp ?? $this->createStub(SendCtcpPort::class),
            $sendNotice ?? $this->createStub(SendNoticePort::class),
            $this->versionResponder,
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $this->createLanguageResolver(),
            ['nickserv' => '002AAAAAA'],
        );
    }
}
