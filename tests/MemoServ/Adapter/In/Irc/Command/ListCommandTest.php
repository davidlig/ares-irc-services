<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\MemoServ\Adapter\In\Irc\Command\ListCommand;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Model\MemoListItem;
use App\MemoServ\Application\UseCase\List\ListMemos;
use App\MemoServ\Application\UseCase\List\ListMemosHandlerInterface;
use App\MemoServ\Application\UseCase\List\ListMemosResult;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
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

#[CoversClass(ListCommand::class)]
final class ListCommandTest extends TestCase
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
    ): MemoServContext {
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$replies): void {
            $replies[] = $m;
        });
        $notifier->method('getNick')->willReturn('MemoServ');

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

        return new MemoServContext(
            sender: $sender,
            senderAccount: $senderAccount,
            command: 'LIST',
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
        $command = new ListCommand($this->createStub(ListMemosHandlerInterface::class));

        self::assertSame('LIST', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(0, $command->getMinArgs());
        self::assertSame('list.syntax', $command->getSyntaxKey());
        self::assertSame('list.help', $command->getHelpKey());
        self::assertSame(3, $command->getOrder());
        self::assertSame('list.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function repliesNotIdentifiedWhenSenderOrAccountNull(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new ListCommand($this->createStub(ListMemosHandlerInterface::class));

        $replies = [];
        $context = $this->createContext($sender, null, [], $replies);
        $command->execute($context);

        self::assertSame(['error.not_identified'], $replies);

        $replies = [];
        $context = $this->createContext(null, new MemoAccountView(1, 'TestUser', 'en'), [], $replies);
        $command->execute($context);

        self::assertSame([], $replies);
    }

    #[Test]
    public function handlesChannelNotRegisteredAndEmpty(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(ListMemosHandlerInterface::class);
        $handler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                ListMemosResult::channelNotRegistered('#unregistered'),
                ListMemosResult::empty('TestUser'),
            );

        $command = new ListCommand($handler);

        $replies = [];
        $context = $this->createContext($sender, $account, ['#unregistered'], $replies);
        $command->execute($context);
        self::assertSame(['list.channel_not_registered [%channel%: #unregistered]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, [], $replies);
        $command->execute($context);
        self::assertSame(['list.empty [%target%: TestUser]'], $replies);
    }

    #[Test]
    public function handlesSuccessWithItemsReadAndUnreadAndPreview(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');
        $date = new DateTimeImmutable('2026-09-06 12:00:00 UTC');

        $longMessage = str_repeat('x', 60);

        $items = [
            new MemoListItem(index: 1, senderDisplay: 'SenderOne', createdAt: $date, message: "Short\nmessage", isRead: false),
            new MemoListItem(index: 2, senderDisplay: 'SenderTwo', createdAt: $date, message: $longMessage, isRead: true),
        ];

        $handler = $this->createMock(ListMemosHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (ListMemos $dto): bool => 1 === $dto->senderNickId && 'TestUser' === $dto->senderNickName && '#chan' === $dto->channelName))
            ->willReturn(ListMemosResult::success('#chan', $items));

        $command = new ListCommand($handler);
        $replies = [];
        $context = $this->createContext($sender, $account, ['#chan'], $replies);
        $command->execute($context);

        self::assertCount(4, $replies);
        self::assertSame('list.header [%target%: #chan]', $replies[0]);
        self::assertStringContainsString('*', $replies[1]);
        self::assertStringContainsString('SenderOne', $replies[1]);
        self::assertStringContainsString('Short message', $replies[1]);
        self::assertStringNotContainsString('*', $replies[2]);
        self::assertStringContainsString('SenderTwo', $replies[2]);
        self::assertStringContainsString(str_repeat('x', 50) . '…', $replies[2]);
        self::assertStringNotContainsString(str_repeat('x', 51), $replies[2]);
        self::assertSame('list.footer', $replies[3]);
    }

    #[Test]
    public function presentsAccessDeniedWithIrcOperationAndChannel(): void
    {
        $handler = $this->createStub(ListMemosHandlerInterface::class);
        $handler->method('handle')->willReturn(ListMemosResult::accessDenied('#Ares'));

        $command = new ListCommand($handler);
        $replies = [];
        $context = $this->createContext(
            new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip'),
            new MemoAccountView(1, 'TestUser', 'en'),
            ['#ares'],
            $replies,
        );
        $command->execute($context);

        self::assertSame(['error.insufficient_access [%channel%: #Ares, %operation%: LIST]'], $replies);
    }
}
