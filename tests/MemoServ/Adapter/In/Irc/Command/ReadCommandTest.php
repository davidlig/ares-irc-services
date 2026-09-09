<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Irc\Command\ReadCommand;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\UseCase\Read\ReadMemo;
use App\MemoServ\Application\UseCase\Read\ReadMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Read\ReadMemoResult;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function is_scalar;
use function ksort;

#[CoversClass(ReadCommand::class)]
final class ReadCommandTest extends TestCase
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

        return new MemoServContext(
            sender: $sender,
            senderAccount: $senderAccount,
            command: 'READ',
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
        $command = new ReadCommand($this->createStub(ReadMemoHandlerInterface::class));

        self::assertSame('READ', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('read.syntax', $command->getSyntaxKey());
        self::assertSame('read.help', $command->getHelpKey());
        self::assertSame(2, $command->getOrder());
        self::assertSame('read.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function repliesNotIdentifiedWhenSenderOrAccountNull(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new ReadCommand($this->createStub(ReadMemoHandlerInterface::class));

        $replies = [];
        $context = $this->createContext($sender, null, ['1'], $replies);
        $command->execute($context);

        self::assertSame(['error.not_identified'], $replies);

        $replies = [];
        $context = $this->createContext(null, new MemoAccountView(1, 'TestUser', 'en'), ['1'], $replies);
        $command->execute($context);

        self::assertSame([], $replies);
    }

    #[Test]
    public function repliesSyntaxWhenIndexNotDigit(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');
        $command = new ReadCommand($this->createStub(ReadMemoHandlerInterface::class));

        $replies = [];
        $context = $this->createContext($sender, $account, ['abc'], $replies);
        $command->execute($context);

        self::assertSame(['error.syntax [%syntax%: read.syntax]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['#chan', 'xyz'], $replies);
        $command->execute($context);

        self::assertSame(['error.syntax [%syntax%: read.syntax]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['#chan'], $replies);
        $command->execute($context);

        self::assertSame(['error.syntax [%syntax%: read.syntax]'], $replies);
    }

    #[Test]
    public function handlesSuccessForNick(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');
        $date = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
        $before = new DateTimeImmutable();

        $handler = $this->createMock(ReadMemoHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (ReadMemo $dto): bool => 1 === $dto->senderNickId
                && null === $dto->channelName
                && 3 === $dto->index
                && $dto->occurredAt->getTimestamp() >= $before->getTimestamp()))
            ->willReturn(ReadMemoResult::success(3, 'SenderNick', 'Test content', $date));

        $command = new ReadCommand($handler);
        $replies = [];
        $context = $this->createContext($sender, $account, ['3'], $replies);
        $command->execute($context);

        self::assertCount(3, $replies);
        self::assertSame('read.header [%from%: SenderNick, %index%: 3]', $replies[0]);
        self::assertSame(' Test content', $replies[1]);
        self::assertStringStartsWith('read.footer [%date%:', $replies[2]);
    }

    #[Test]
    public function handlesSuccessForChannel(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');
        $date = new DateTimeImmutable('2026-09-06 12:00:00 UTC');
        $before = new DateTimeImmutable();

        $handler = $this->createMock(ReadMemoHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (ReadMemo $dto): bool => 1 === $dto->senderNickId
                && '#chan' === $dto->channelName
                && 1 === $dto->index
                && $dto->occurredAt->getTimestamp() >= $before->getTimestamp()))
            ->willReturn(ReadMemoResult::success(1, 'SenderNick', 'Channel memo', $date));

        $command = new ReadCommand($handler);
        $replies = [];
        $context = $this->createContext($sender, $account, ['#chan', '1'], $replies);
        $command->execute($context);

        self::assertCount(3, $replies);
        self::assertSame('read.header [%from%: SenderNick, %index%: 1]', $replies[0]);
        self::assertSame(' Channel memo', $replies[1]);
    }

    #[Test]
    public function handlesNotFoundAndChannelNotRegistered(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(ReadMemoHandlerInterface::class);
        $handler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                ReadMemoResult::notFound(5),
                ReadMemoResult::channelNotRegistered('#unregistered'),
            );

        $command = new ReadCommand($handler);

        $replies = [];
        $context = $this->createContext($sender, $account, ['5'], $replies);
        $command->execute($context);
        self::assertSame(['read.not_found [%index%: 5]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['#unregistered', '1'], $replies);
        $command->execute($context);
        self::assertSame(['read.channel_not_registered [%channel%: #unregistered]'], $replies);
    }

    #[Test]
    public function presentsAccessDeniedWithIrcOperationAndChannel(): void
    {
        $handler = $this->createStub(ReadMemoHandlerInterface::class);
        $handler->method('handle')->willReturn(ReadMemoResult::accessDenied('#Ares'));

        $command = new ReadCommand($handler);
        $replies = [];
        $context = $this->createContext(
            new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip'),
            new MemoAccountView(1, 'TestUser', 'en'),
            ['#ares', '1'],
            $replies,
        );
        $command->execute($context);

        self::assertSame(['error.insufficient_access [%channel%: #Ares, %operation%: READ]'], $replies);
    }
}
