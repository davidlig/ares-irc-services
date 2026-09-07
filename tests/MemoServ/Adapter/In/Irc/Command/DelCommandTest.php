<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\MemoServ\Adapter\In\Irc\Command\DelCommand;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\UseCase\Del\DelMemo;
use App\MemoServ\Application\UseCase\Del\DelMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Del\DelMemoResult;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

use function implode;
use function is_scalar;
use function ksort;

#[CoversClass(DelCommand::class)]
final class DelCommandTest extends TestCase
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
            command: 'DEL',
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
        $command = new DelCommand($this->createStub(DelMemoHandlerInterface::class));

        self::assertSame('DEL', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('del.syntax', $command->getSyntaxKey());
        self::assertSame('del.help', $command->getHelpKey());
        self::assertSame(4, $command->getOrder());
        self::assertSame('del.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function repliesNotIdentifiedWhenSenderOrAccountNull(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new DelCommand($this->createStub(DelMemoHandlerInterface::class));

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
        $command = new DelCommand($this->createStub(DelMemoHandlerInterface::class));

        $replies = [];
        $context = $this->createContext($sender, $account, ['abc'], $replies);
        $command->execute($context);
        self::assertSame(['error.syntax [%syntax%: del.syntax]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['#channel', 'xyz'], $replies);
        $command->execute($context);
        self::assertSame(['error.syntax [%syntax%: del.syntax]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['#channel'], $replies);
        $command->execute($context);
        self::assertSame(['error.syntax [%syntax%: del.syntax]'], $replies);
    }

    #[Test]
    public function handlesDeletedForNick(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(DelMemoHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (DelMemo $dto): bool => 1 === $dto->senderNickId && null === $dto->channelName && 2 === $dto->index))
            ->willReturn(DelMemoResult::deleted(2));

        $command = new DelCommand($handler);
        $replies = [];
        $context = $this->createContext($sender, $account, ['2'], $replies);
        $command->execute($context);

        self::assertSame(['del.deleted [%index%: 2]'], $replies);
    }

    #[Test]
    public function handlesDeletedForChannel(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(DelMemoHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (DelMemo $dto): bool => 1 === $dto->senderNickId && '#chan' === $dto->channelName && 1 === $dto->index))
            ->willReturn(DelMemoResult::deleted(1));

        $command = new DelCommand($handler);
        $replies = [];
        $context = $this->createContext($sender, $account, ['#chan', '1'], $replies);
        $command->execute($context);

        self::assertSame(['del.deleted [%index%: 1]'], $replies);
    }

    #[Test]
    public function handlesNotFoundAndChannelNotRegistered(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(DelMemoHandlerInterface::class);
        $handler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                DelMemoResult::notFound(9),
                DelMemoResult::channelNotRegistered('#unregistered'),
            );

        $command = new DelCommand($handler);

        $replies = [];
        $context = $this->createContext($sender, $account, ['9'], $replies);
        $command->execute($context);
        self::assertSame(['del.not_found [%index%: 9]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['#unregistered', '1'], $replies);
        $command->execute($context);
        self::assertSame(['del.channel_not_registered [%channel%: #unregistered]'], $replies);
    }

    #[Test]
    public function presentsAccessDeniedWithIrcOperationAndChannel(): void
    {
        $handler = $this->createStub(DelMemoHandlerInterface::class);
        $handler->method('handle')->willReturn(DelMemoResult::accessDenied('#Ares'));

        $command = new DelCommand($handler);
        $replies = [];
        $context = $this->createContext(
            new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip'),
            new MemoAccountView(1, 'TestUser', 'en'),
            ['#ares', '1'],
            $replies,
        );
        $command->execute($context);

        self::assertSame(['error.insufficient_access [%channel%: #Ares, %operation%: DEL]'], $replies);
    }
}
