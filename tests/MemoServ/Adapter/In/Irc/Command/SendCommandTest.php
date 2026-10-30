<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Irc\Command\SendCommand;
use App\MemoServ\Adapter\In\Irc\MemoNoticePresenter;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNoticeHandler;
use App\MemoServ\Application\UseCase\Send\SendMemo;
use App\MemoServ\Application\UseCase\Send\SendMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Send\SendMemoResult;
use App\MemoServ\Domain\Entity\Memo;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function is_scalar;
use function ksort;
use function str_repeat;

#[CoversClass(SendCommand::class)]
final class SendCommandTest extends TestCase
{
    #[Test]
    public function sendsRenderedPendingNoticeInRecipientsLanguage(): void
    {
        $sender = new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip');
        $account = new MemoAccountView(1, 'Nick', 'en');
        $handler = $this->createStub(SendMemoHandlerInterface::class);
        $handler->method('handle')->willReturn(SendMemoResult::sentToNick(2, 'Bob', 2, 'fr'));
        $lookup = $this->createStub(NetworkUserLookupPort::class);
        $lookup->method('findByNick')->willReturn(new SenderView('REC', 'Bob', 'i', 'h', 'c', 'ip'));
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('MemoServ');
        $notifier->expects(self::once())->method('sendNotice')->with('REC', 'i Nick /msg MemoServ LIST');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => 'notify.nick_received' === $key ? '%sender% /msg %bot% %list%' : $key);
        $presenter = new MemoNoticePresenter($translator, new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['information' => 'i']))));
        $context = $this->createContext($sender, $account, ['Bob', 'hello'], notifier: $notifier, translator: $translator);
        $this->command($handler, $lookup, $translator, $presenter)->execute($context);
    }

    #[Test]
    public function rendersDifferentSendOutcomesWithTheSameLocalizedCommand(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$sent): void {
            $sent[] = [$uid, $message, $type];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => match ($key) {
            'send.sent_nick' => 'To %nickname%, delivered',
            'send.throttled' => 'Wait %seconds%',
            default => $key,
        });
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(
            components: ['emphasis' => new Appearance(bold: true)],
            markers: ['success' => '+', 'warning' => '!'],
        )));
        $context = new MemoServContext(
            new SenderView('UID', 'Sender', 'i', 'h', 'c', 'ip'),
            new MemoAccountView(1, 'Sender', 'en'),
            'SEND',
            ['Bob', 'hello'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'PRIVMSG',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
            $renderer,
        );
        $handler = $this->createStub(SendMemoHandlerInterface::class);
        $handler->method('handle')->willReturnOnConsecutiveCalls(
            SendMemoResult::sentToNick(2, 'Bob', 0, 'en'),
            SendMemoResult::throttled(3),
        );
        $command = $this->command($handler, $this->createStub(NetworkUserLookupPort::class), $translator);
        $command->execute($context);
        $command->execute($context);

        self::assertSame([
            ['UID', "+ To \x02Bob\x03\x0F, delivered", 'PRIVMSG'],
            ['UID', "! Wait \x023\x03\x0F", 'PRIVMSG'],
        ], $sent);
    }

    #[Test]
    public function exposesMetadata(): void
    {
        $command = $this->command(
            $this->createStub(SendMemoHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
        );

        self::assertSame('SEND', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('send.syntax', $command->getSyntaxKey());
        self::assertSame('send.help', $command->getHelpKey());
        self::assertSame('send.example', $command->getExampleKey());
        self::assertSame(1, $command->getOrder());
        self::assertSame('send.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function repliesNotIdentifiedWhenSenderOrAccountNull(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = $this->command(
            $this->createStub(SendMemoHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
        );

        $replies = [];
        $context = $this->createContext($sender, null, ['target', 'message'], $replies);
        $command->execute($context);

        self::assertSame(['error.not_identified'], $replies);

        $replies = [];
        $context = $this->createContext(null, new MemoAccountView(1, 'TestUser', 'en'), ['target', 'message'], $replies);
        $command->execute($context);

        self::assertSame([], $replies);
    }

    #[Test]
    public function repliesSyntaxWhenMessageIsEmpty(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $command = $this->command(
            $this->createStub(SendMemoHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
        );

        $replies = [];
        $context = $this->createContext($sender, $account, ['target', ''], $replies);
        $command->execute($context);

        self::assertSame(['error.syntax [%syntax%: send.syntax]'], $replies);
    }

    #[Test]
    public function repliesMessageTooLongWhenMessageExceedsLimit(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $command = $this->command(
            $this->createStub(SendMemoHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
        );

        $replies = [];
        $longMessage = str_repeat('a', Memo::MESSAGE_MAX_LENGTH + 1);
        $context = $this->createContext($sender, $account, ['target', $longMessage], $replies);
        $command->execute($context);

        self::assertSame(['send.message_too_long [%max%: 255]'], $replies);
    }

    #[Test]
    public function handlesSentToNickWithOnlineRecipientNotification(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');
        $before = new DateTimeImmutable();

        $handler = $this->createMock(SendMemoHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (SendMemo $dto): bool => 1 === $dto->senderNickId
                && '001ABC' === $dto->senderUid
                && 'Recipient' === $dto->target
                && 'Hello world' === $dto->message
                && $dto->occurredAt->getTimestamp() >= $before->getTimestamp()))
            ->willReturn(SendMemoResult::sentToNick(
                targetNickId: 2,
                targetNick: 'Recipient',
                unreadCount: 2,
                recipientLanguage: 'fr',
            ));

        $recipientSender = new SenderView('001REC', 'Recipient', 'ident', 'host', 'cloak', 'ip');
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())
            ->method('findByNick')
            ->with('Recipient')
            ->willReturn($recipientSender);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(2))
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $params = []): string {
                if ('notify.nick_received' === $id) {
                    return 'Nouveau mémo';
                }

                return $id;
            });

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('MemoServ');
        $notifier->expects(self::once())
            ->method('sendMessage')
            ->with('001ABC', self::anything(), 'NOTICE');
        $notifier->expects(self::once())
            ->method('sendNotice')
            ->with('001REC', self::stringContains('Nouveau mémo'));

        $command = $this->command($handler, $userLookup, $translator);

        $context = $this->createContext($sender, $account, ['Recipient', 'Hello', 'world'], notifier: $notifier, translator: $translator);
        $command->execute($context);
    }

    #[Test]
    public function handlesSentToNickWhenRecipientOfflineOrNoUnread(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(SendMemoHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn(SendMemoResult::sentToNick(
                targetNickId: 2,
                targetNick: 'Recipient',
                unreadCount: 0,
                recipientLanguage: 'en',
            ));

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByNick')->with('Recipient')->willReturn(null);

        $replies = [];
        $command = $this->command($handler, $userLookup, $this->createStub(TranslatorInterface::class));
        $context = $this->createContext($sender, $account, ['Recipient', 'Hello'], $replies);
        $command->execute($context);

        self::assertSame(['send.sent_nick [%nickname%: Recipient]'], $replies);
    }

    #[Test]
    public function handlesSentToChannel(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(SendMemoHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturn(SendMemoResult::sentToChannel('#channel'));

        $replies = [];
        $command = $this->command($handler, $this->createStub(NetworkUserLookupPort::class), $this->createStub(TranslatorInterface::class));
        $context = $this->createContext($sender, $account, ['#channel', 'Announcement'], $replies);
        $command->execute($context);

        self::assertSame(['send.sent_channel [%channel%: #channel]'], $replies);
    }

    #[Test]
    public function handlesAllOtherOutcomes(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $outcomes = [
            [
                SendMemoResult::throttled(45),
                'send.throttled [%seconds%: 45]',
            ],
            [
                SendMemoResult::cannotSendToSelf(),
                'send.cannot_send_to_self',
            ],
            [
                SendMemoResult::nickNotRegistered('Unknown'),
                'error.nick_not_registered [%nick%: Unknown]',
            ],
            [
                SendMemoResult::channelNotRegistered('#chan'),
                'error.channel_not_registered [%channel%: #chan]',
            ],
            [
                SendMemoResult::ignored(),
                'send.ignored',
            ],
            [
                SendMemoResult::limitReached('Recipient'),
                'send.limit_reached [%target%: Recipient]',
            ],
        ];

        foreach ($outcomes as [$result, $expectedReply]) {
            $handler = $this->createMock(SendMemoHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->willReturn($result);

            $replies = [];
            $command = $this->command($handler, $this->createStub(NetworkUserLookupPort::class), $this->createStub(TranslatorInterface::class));
            $context = $this->createContext($sender, $account, ['target', 'msg'], $replies);
            $command->execute($context);

            self::assertSame([$expectedReply], $replies);
        }
    }

    #[Test]
    public function notifiesOnlyConnectedIdentifiedChannelReadersInTheirLanguage(): void
    {
        $handler = $this->createStub(SendMemoHandlerInterface::class);
        $handler->method('handle')->willReturn(SendMemoResult::sentToChannel('#room'));
        $lookup = $this->createStub(NetworkUserLookupPort::class);
        $lookup->method('listConnectedUids')->willReturn(['reader', 'denied', 'guest', 'gone', 'unknown', 'other-reader']);
        $lookup->method('findByUid')->willReturnCallback(static fn (string $uid): ?SenderView => 'gone' === $uid ? null : new SenderView($uid, $uid, 'i', 'h', 'c', 'ip', 'guest' !== $uid));
        $channel = $this->createStub(MemoChannelPort::class);
        $channel->method('findChannelByName')->willReturn(new MemoChannelView(5, '#room'));
        $channel->method('canReadChannelMemos')->willReturnCallback(static fn (int $channelId, int $nickId): bool => 2 === $nickId);
        $accounts = $this->createStub(MemoUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturnCallback(static fn (string $nick): ?MemoAccountView => 'unknown' === $nick ? null : new MemoAccountView('denied' === $nick ? 3 : 2, $nick, 'fr'));
        $repository = $this->createStub(MemoRepositoryInterface::class);
        $repository->method('countUnreadByTargetChannel')->willReturn(1);
        $settings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settings->method('isEnabledForChannel')->willReturn(true);
        $query = new GetPendingChannelNoticeHandler($channel, $accounts, $repository, $settings);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key, array $params, ?string $domain, ?string $locale): string => 'notify.channel_received' === $key ? $locale . ' %sender% %channel%' : $key);
        $notices = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('MemoServ');
        $notifier->method('sendNotice')->willReturnCallback(static function (string $uid, string $text) use (&$notices): void { $notices[$uid] = $text; });
        $presenter = new MemoNoticePresenter($translator, new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['information' => 'i']))));
        $context = $this->createContext(new SenderView('sender', 'Alice', 'i', 'h', 'c', 'ip', true), new MemoAccountView(1, 'Alice', 'en'), ['#room', 'hello'], notifier: $notifier, translator: $translator);
        new SendCommand($handler, $lookup, $translator, $query, $presenter)->execute($context);
        self::assertSame(['reader' => 'i fr Alice #room', 'other-reader' => 'i fr Alice #room'], $notices);
    }

    private function command(SendMemoHandlerInterface $handler, NetworkUserLookupPort $lookup, TranslatorInterface $translator, ?MemoNoticePresenter $presenter = null): SendCommand
    {
        return new SendCommand($handler, $lookup, $translator, new GetPendingChannelNoticeHandler($this->createStub(MemoChannelPort::class), $this->createStub(MemoUserAccountPort::class), $this->createStub(MemoRepositoryInterface::class), $this->createStub(MemoSettingsRepositoryInterface::class)), $presenter);
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'memoserv';
            }

            public function getNickname(): string
            {
                return 'MemoServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }

    /**
     * @param list<string> $args
     * @param string[]     $replies
     */
    private function createContext(
        ?SenderView $sender,
        ?MemoAccountView $senderAccount,
        array $args,
        array &$replies = [],
        ?MemoServNotifierInterface $notifier = null,
        ?TranslatorInterface $translator = null,
    ): MemoServContext {
        if (null === $notifier) {
            $notifier = $this->createStub(MemoServNotifierInterface::class);
            $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$replies): void {
                $replies[] = $m;
            });
            $notifier->method('getNick')->willReturn('MemoServ');
        }

        if (null === $translator) {
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(static function (string $id, array $params = []): string {
                unset($params['%bot%'], $params['%memoserv%']);
                if ([] === $params) {
                    return $id;
                }
                ksort($params);
                $formatted = [];
                foreach ($params as $key => $value) {
                    $formatted[] = (string) $key . ': ' . (is_scalar($value) || $value instanceof Stringable ? (string) $value : '');
                }

                return $id . ' [' . implode(', ', $formatted) . ']';
            });
        }

        return new MemoServContext(
            sender: $sender,
            senderAccount: $senderAccount,
            command: 'SEND',
            args: $args,
            notifier: $notifier,
            translator: $translator,
            language: 'en',
            timezone: 'UTC',
            messageType: 'NOTICE',
            registry: new MemoServCommandRegistry([]),
            serviceNicks: $this->createServiceNicks(),
        );
    }
}
