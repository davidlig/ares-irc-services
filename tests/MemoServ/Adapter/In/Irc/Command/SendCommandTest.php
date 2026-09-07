<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\MemoServ\Adapter\In\Irc\Command\SendCommand;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\UseCase\Send\SendMemo;
use App\MemoServ\Application\UseCase\Send\SendMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Send\SendMemoResult;
use App\MemoServ\Domain\Entity\Memo;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

use function implode;
use function is_scalar;
use function ksort;
use function str_repeat;

#[CoversClass(SendCommand::class)]
final class SendCommandTest extends TestCase
{
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
     * @param string[] $args
     * @param string[] $replies
     */
    private function createContext(
        ?SenderView $sender,
        ?MemoAccountView $senderAccount,
        array $args,
        array &$replies = [],
        ?MemoServNotifierInterface $notifier = null,
        ?TranslationInterface $translator = null,
    ): MemoServContext {
        if (null === $notifier) {
            $notifier = $this->createStub(MemoServNotifierInterface::class);
            $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$replies): void {
                $replies[] = $m;
            });
            $notifier->method('getNick')->willReturn('MemoServ');
        }

        if (null === $translator) {
            $translator = $this->createStub(TranslationInterface::class);
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

    #[Test]
    public function exposesMetadata(): void
    {
        $command = new SendCommand(
            $this->createStub(SendMemoHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslationInterface::class),
        );

        self::assertSame('SEND', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('send.syntax', $command->getSyntaxKey());
        self::assertSame('send.help', $command->getHelpKey());
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
        $command = new SendCommand(
            $this->createStub(SendMemoHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslationInterface::class),
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

        $command = new SendCommand(
            $this->createStub(SendMemoHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslationInterface::class),
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

        $command = new SendCommand(
            $this->createStub(SendMemoHandlerInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslationInterface::class),
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

        $translator = $this->createMock(TranslationInterface::class);
        $translator->expects(self::exactly(2))
            ->method('trans')
            ->willReturnCallback(static function (string $id, array $params = []): string {
                if ('notify.nick_pending' === $id) {
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
            ->with('001REC', 'Nouveau mémo');

        $command = new SendCommand($handler, $userLookup, $translator);

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
        $userLookup->expects(self::never())->method('findByNick');

        $replies = [];
        $command = new SendCommand($handler, $userLookup, $this->createStub(TranslationInterface::class));
        $context = $this->createContext($sender, $account, ['Recipient', 'Hello'], $replies);
        $command->execute($context);

        self::assertSame(['send.sent_nick [%nick%: Recipient]'], $replies);
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
        $command = new SendCommand($handler, $this->createStub(NetworkUserLookupPort::class), $this->createStub(TranslationInterface::class));
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
                'send.nick_not_registered [%nick%: Unknown]',
            ],
            [
                SendMemoResult::channelNotRegistered('#chan'),
                'send.channel_not_registered [%channel%: #chan]',
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
            $command = new SendCommand($handler, $this->createStub(NetworkUserLookupPort::class), $this->createStub(TranslationInterface::class));
            $context = $this->createContext($sender, $account, ['target', 'msg'], $replies);
            $command->execute($context);

            self::assertSame([$expectedReply], $replies);
        }
    }
}
