<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\MemoServ\Adapter\In\Irc\Command\EnableCommand;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\UseCase\Enable\EnableMemos;
use App\MemoServ\Application\UseCase\Enable\EnableMemosHandlerInterface;
use App\MemoServ\Application\UseCase\Enable\EnableMemosResult;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

use function implode;
use function is_scalar;
use function ksort;

#[CoversClass(EnableCommand::class)]
final class EnableCommandTest extends TestCase
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
            command: 'ENABLE',
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
        $command = new EnableCommand($this->createStub(EnableMemosHandlerInterface::class));

        self::assertSame('ENABLE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(0, $command->getMinArgs());
        self::assertSame('enable.syntax', $command->getSyntaxKey());
        self::assertSame('enable.help', $command->getHelpKey());
        self::assertSame(6, $command->getOrder());
        self::assertSame('enable.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function repliesNotIdentifiedWhenSenderOrAccountNull(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new EnableCommand($this->createStub(EnableMemosHandlerInterface::class));

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
    public function handlesEnabledForNickAndAlreadyEnabled(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(EnableMemosHandlerInterface::class);
        $handler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                EnableMemosResult::enabledNick(),
                EnableMemosResult::alreadyEnabledNick(),
            );

        $command = new EnableCommand($handler);

        $replies = [];
        $context = $this->createContext($sender, $account, [], $replies);
        $command->execute($context);
        self::assertSame(['enable.enabled_nick'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, [], $replies);
        $command->execute($context);
        self::assertSame(['enable.already_enabled_nick'], $replies);
    }

    #[Test]
    public function handlesChannelOutcomes(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $outcomes = [
            [EnableMemosResult::enabledChannel('#chan'), 'enable.enabled_channel [%channel%: #chan]'],
            [EnableMemosResult::alreadyEnabledChannel('#chan'), 'enable.already_enabled_channel [%channel%: #chan]'],
            [EnableMemosResult::channelNotRegistered('#chan'), 'enable.channel_not_registered [%channel%: #chan]'],
            [EnableMemosResult::founderOnly('#chan'), 'enable.founder_only [%channel%: #chan]'],
        ];

        foreach ($outcomes as [$result, $expectedReply]) {
            $handler = $this->createMock(EnableMemosHandlerInterface::class);
            $handler->expects(self::once())
                ->method('handle')
                ->with(self::callback(static fn (EnableMemos $dto): bool => 1 === $dto->senderNickId && '#chan' === $dto->channelName))
                ->willReturn($result);

            $command = new EnableCommand($handler);

            $replies = [];
            $context = $this->createContext($sender, $account, ['#chan'], $replies);
            $command->execute($context);
            self::assertSame([$expectedReply], $replies);
        }
    }
}
