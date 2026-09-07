<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\MemoServ\Adapter\In\Irc\Command\IgnoreCommand;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoHandlerInterface;
use App\MemoServ\Application\UseCase\Ignore\IgnoreMemoResult;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

use function implode;
use function is_scalar;
use function ksort;

#[CoversClass(IgnoreCommand::class)]
final class IgnoreCommandTest extends TestCase
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
            command: 'IGNORE',
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
        $command = new IgnoreCommand($this->createStub(IgnoreMemoHandlerInterface::class), 10, 20);

        self::assertSame('IGNORE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('ignore.syntax', $command->getSyntaxKey());
        self::assertSame('ignore.help', $command->getHelpKey());
        self::assertSame(5, $command->getOrder());
        self::assertSame('ignore.short', $command->getShortDescKey());
        self::assertCount(3, $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function repliesNotIdentifiedWhenSenderOrAccountNull(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new IgnoreCommand($this->createStub(IgnoreMemoHandlerInterface::class), 10, 20);

        $replies = [];
        $context = $this->createContext($sender, null, ['LIST'], $replies);
        $command->execute($context);
        self::assertSame(['error.not_identified'], $replies);

        $replies = [];
        $context = $this->createContext(null, new MemoAccountView(1, 'TestUser', 'en'), ['LIST'], $replies);
        $command->execute($context);
        self::assertSame([], $replies);
    }

    #[Test]
    public function repliesSyntaxWhenSubcommandInvalid(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');
        $command = new IgnoreCommand($this->createStub(IgnoreMemoHandlerInterface::class), 10, 20);

        $replies = [];
        $context = $this->createContext($sender, $account, ['UNKNOWN'], $replies);
        $command->execute($context);

        self::assertSame(['error.syntax [%syntax%: ignore.syntax]'], $replies);
    }

    #[Test]
    public function repliesSyntaxWhenNickMissingForAddOrDel(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');
        $command = new IgnoreCommand($this->createStub(IgnoreMemoHandlerInterface::class), 10, 20);

        $replies = [];
        $context = $this->createContext($sender, $account, ['ADD'], $replies);
        $command->execute($context);
        self::assertSame(['error.syntax [%syntax%: ignore.add.syntax]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['ADD', '#chan'], $replies);
        $command->execute($context);
        self::assertSame(['error.syntax [%syntax%: ignore.add.syntax]'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['DEL'], $replies);
        $command->execute($context);
        self::assertSame(['error.syntax [%syntax%: ignore.del.syntax]'], $replies);
    }

    #[Test]
    public function handlesListEmptyAndWithItems(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(IgnoreMemoHandlerInterface::class);
        $handler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                IgnoreMemoResult::listNick([]),
                IgnoreMemoResult::listChannel('#chan', ['BadUser1', 'BadUser2']),
            );

        $command = new IgnoreCommand($handler, 10, 20);

        $replies = [];
        $context = $this->createContext($sender, $account, ['LIST'], $replies);
        $command->execute($context);
        self::assertSame(['ignore.list_empty'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, ['LIST', '#chan'], $replies);
        $command->execute($context);
        self::assertSame([
            'ignore.list_header',
            '  BadUser1',
            '  BadUser2',
            'ignore.list_footer',
        ], $replies);
    }

    #[Test]
    public function handlesAddNickAndAddChannel(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(IgnoreMemoHandlerInterface::class);
        $handler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                IgnoreMemoResult::addedNick('IgnoredUser'),
                IgnoreMemoResult::addedChannel('#chan', 'IgnoredUser'),
            );

        $command = new IgnoreCommand($handler, 10, 20);

        $replies = [];
        $context = $this->createContext($sender, $account, ['ADD', 'IgnoredUser'], $replies);
        $command->execute($context);
        self::assertStringContainsString('ignore.added', $replies[0]);
        self::assertStringContainsString('IgnoredUser', $replies[0]);

        $replies = [];
        $context = $this->createContext($sender, $account, ['ADD', '#chan', 'IgnoredUser'], $replies);
        $command->execute($context);
        self::assertStringContainsString('ignore.added', $replies[0]);
        self::assertStringContainsString('IgnoredUser', $replies[0]);
    }

    #[Test]
    public function handlesDelNickAndDelChannel(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(IgnoreMemoHandlerInterface::class);
        $handler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                IgnoreMemoResult::deletedNick('IgnoredUser'),
                IgnoreMemoResult::deletedChannel('#chan', 'IgnoredUser'),
            );

        $command = new IgnoreCommand($handler, 10, 20);

        $replies = [];
        $context = $this->createContext($sender, $account, ['DEL', 'IgnoredUser'], $replies);
        $command->execute($context);
        self::assertStringContainsString('ignore.removed', $replies[0]);
        self::assertStringContainsString('IgnoredUser', $replies[0]);

        $replies = [];
        $context = $this->createContext($sender, $account, ['DEL', '#chan', 'IgnoredUser'], $replies);
        $command->execute($context);
        self::assertStringContainsString('ignore.removed', $replies[0]);
        self::assertStringContainsString('IgnoredUser', $replies[0]);
    }

    #[Test]
    public function handlesAllOtherOutcomes(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $outcomes = [
            [IgnoreMemoResult::alreadyIgnored('UserA'), 'ignore.already_ignored'],
            [IgnoreMemoResult::notIgnored('UserA'), 'ignore.not_ignored'],
            [IgnoreMemoResult::nickNotRegistered('UserA'), 'ignore.nick_not_registered'],
            [IgnoreMemoResult::channelNotRegistered('#chan'), 'ignore.channel_not_registered'],
            [IgnoreMemoResult::limitReached(), 'ignore.limit_reached_nick'],
        ];

        foreach ($outcomes as [$result, $expectedPrefix]) {
            $handler = $this->createMock(IgnoreMemoHandlerInterface::class);
            $handler->expects(self::once())->method('handle')->willReturn($result);

            $command = new IgnoreCommand($handler, 10, 20);

            $replies = [];
            $context = $this->createContext($sender, $account, ['ADD', 'UserA'], $replies);
            $command->execute($context);
            self::assertStringContainsString($expectedPrefix, $replies[0]);
        }

        // Limit reached for channel
        $handler = $this->createMock(IgnoreMemoHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willReturn(IgnoreMemoResult::limitReached('#chan'));

        $command = new IgnoreCommand($handler, 10, 20);
        $replies = [];
        $context = $this->createContext($sender, $account, ['ADD', '#chan', 'UserA'], $replies);
        $command->execute($context);
        self::assertStringContainsString('ignore.limit_reached_channel', $replies[0]);
    }

    #[Test]
    public function presentsAccessDeniedWithIrcOperationAndChannel(): void
    {
        $handler = $this->createStub(IgnoreMemoHandlerInterface::class);
        $handler->method('handle')->willReturn(IgnoreMemoResult::accessDenied('#Ares'));

        $command = new IgnoreCommand($handler, 10, 20);
        $replies = [];
        $context = $this->createContext(
            new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip'),
            new MemoAccountView(1, 'TestUser', 'en'),
            ['ADD', '#ares', 'Bob'],
            $replies,
        );
        $command->execute($context);

        self::assertSame(['error.insufficient_access [%channel%: #Ares, %operation%: IGNORE]'], $replies);
    }
}
