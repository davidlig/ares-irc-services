<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Irc\Command\DisableCommand;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\UseCase\Disable\DisableMemos;
use App\MemoServ\Application\UseCase\Disable\DisableMemosHandlerInterface;
use App\MemoServ\Application\UseCase\Disable\DisableMemosResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function implode;
use function is_scalar;
use function ksort;

#[CoversClass(DisableCommand::class)]
final class DisableCommandTest extends TestCase
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
            command: 'DISABLE',
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
        $command = new DisableCommand($this->createStub(DisableMemosHandlerInterface::class));

        self::assertSame('DISABLE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(0, $command->getMinArgs());
        self::assertSame('disable.syntax', $command->getSyntaxKey());
        self::assertSame('disable.help', $command->getHelpKey());
        self::assertSame(7, $command->getOrder());
        self::assertSame('disable.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
    }

    #[Test]
    public function repliesNotIdentifiedWhenSenderOrAccountNull(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new DisableCommand($this->createStub(DisableMemosHandlerInterface::class));

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
    public function handlesDisabledForNickAndAlreadyDisabled(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $handler = $this->createMock(DisableMemosHandlerInterface::class);
        $handler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                DisableMemosResult::disabledNick(),
                DisableMemosResult::alreadyDisabledNick(),
            );

        $command = new DisableCommand($handler);

        $replies = [];
        $context = $this->createContext($sender, $account, [], $replies);
        $command->execute($context);
        self::assertSame(['disable.disabled_nick'], $replies);

        $replies = [];
        $context = $this->createContext($sender, $account, [], $replies);
        $command->execute($context);
        self::assertSame(['disable.already_disabled_nick'], $replies);
    }

    #[Test]
    public function handlesChannelOutcomes(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestUser', 'en');

        $outcomes = [
            [DisableMemosResult::disabledChannel('#chan'), 'disable.disabled_channel [%channel%: #chan]'],
            [DisableMemosResult::alreadyDisabledChannel('#chan'), 'disable.already_disabled_channel [%channel%: #chan]'],
            [DisableMemosResult::channelNotRegistered('#chan'), 'disable.channel_not_registered [%channel%: #chan]'],
            [DisableMemosResult::founderOnly('#chan'), 'disable.founder_only [%channel%: #chan]'],
        ];

        foreach ($outcomes as [$result, $expectedReply]) {
            $handler = $this->createMock(DisableMemosHandlerInterface::class);
            $handler->expects(self::once())
                ->method('handle')
                ->with(self::callback(static fn (DisableMemos $dto): bool => 1 === $dto->senderNickId && '#chan' === $dto->channelName))
                ->willReturn($result);

            $command = new DisableCommand($handler);

            $replies = [];
            $context = $this->createContext($sender, $account, ['#chan'], $replies);
            $command->execute($context);
            self::assertSame([$expectedReply], $replies);
        }
    }
}
