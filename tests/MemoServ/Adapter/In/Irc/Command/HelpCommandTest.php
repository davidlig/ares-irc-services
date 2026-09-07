<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Application\Shared\Help\UnifiedHelpFormatter;
use App\Irc\Application\Port\In\SenderView;
use App\MemoServ\Adapter\In\Irc\Command\HelpCommand;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;

use function implode;
use function is_scalar;
use function ksort;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
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
     * @param string[]                           $args
     * @param string[]                           $replies
     * @param iterable<MemoServCommandInterface> $commands
     */
    private function createContext(
        ?SenderView $sender,
        ?MemoAccountView $senderAccount,
        array $args,
        array &$replies = [],
        iterable $commands = [],
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
            command: 'HELP',
            args: $args,
            notifier: $notifier,
            translator: $translator,
            language: 'en',
            timezone: 'UTC',
            messageType: 'NOTICE',
            registry: new MemoServCommandRegistry($commands),
            serviceNicks: $this->createServiceNicks(),
        );
    }

    /**
     * @param list<array{name: string, desc_key: string, help_key: string, syntax_key: string}> $subcommands
     */
    private function createDummyCommand(string $name, int $order = 1, array $subcommands = []): MemoServCommandInterface
    {
        return new class($name, $order, $subcommands) implements MemoServCommandInterface {
            /**
             * @param list<array{name: string, desc_key: string, help_key: string, syntax_key: string}> $subcommands
             */
            public function __construct(
                private string $name,
                private int $order,
                private array $subcommands,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'dummy.syntax';
            }

            public function getHelpKey(): string
            {
                return 'dummy.help';
            }

            public function getOrder(): int
            {
                return $this->order;
            }

            public function getShortDescKey(): string
            {
                return 'dummy.short';
            }

            /**
             * @return list<array{name: string, desc_key: string, help_key: string, syntax_key: string}>
             */
            public function getSubCommandHelp(): array
            {
                return $this->subcommands;
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(MemoServContext $context): void {}
        };
    }

    #[Test]
    public function exposesMetadata(): void
    {
        $command = new HelpCommand(new UnifiedHelpFormatter());

        self::assertSame('HELP', $command->getName());
        self::assertSame(['?'], $command->getAliases());
        self::assertSame(0, $command->getMinArgs());
        self::assertSame('help.syntax', $command->getSyntaxKey());
        self::assertSame('help.help', $command->getHelpKey());
        self::assertSame(99, $command->getOrder());
        self::assertSame('help.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
    }

    #[Test]
    public function showsGeneralHelpWhenArgsEmpty(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $cmd1 = $this->createDummyCommand('SEND', 1);
        $cmd2 = $this->createDummyCommand('READ', 2);

        $replies = [];
        $context = $this->createContext($sender, null, [], $replies, [$cmd1, $cmd2, $command]);
        $command->execute($context);

        self::assertNotEmpty($replies);
        self::assertStringContainsString('help.header_title', $replies[0]);
        self::assertContains('help.footer', $replies);
    }

    #[Test]
    public function repliesUnknownCommandWhenCommandNotFound(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $replies = [];
        $context = $this->createContext($sender, null, ['NONEXISTENT'], $replies, [$command]);
        $command->execute($context);

        self::assertSame(['help.unknown_command [%command%: NONEXISTENT]'], $replies);
    }

    #[Test]
    public function showsCommandHelpWhenCommandFoundWithoutSubcommand(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $targetCmd = $this->createDummyCommand('SEND', 1);

        $replies = [];
        $context = $this->createContext($sender, null, ['SEND'], $replies, [$targetCmd, $command]);
        $command->execute($context);

        self::assertNotEmpty($replies);
        self::assertStringContainsString('SEND', $replies[0]);
    }

    #[Test]
    public function showsSubCommandHelpWhenSubcommandMatches(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $subs = [
            ['name' => 'ADD', 'desc_key' => 'ignore.add.short', 'help_key' => 'ignore.add.help', 'syntax_key' => 'ignore.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'ignore.del.short', 'help_key' => 'ignore.del.help', 'syntax_key' => 'ignore.del.syntax'],
        ];
        $targetCmd = $this->createDummyCommand('IGNORE', 1, $subs);

        $replies = [];
        $context = $this->createContext($sender, null, ['IGNORE', 'ADD'], $replies, [$targetCmd, $command]);
        $command->execute($context);

        self::assertNotEmpty($replies);
        self::assertStringContainsString('IGNORE ADD', $replies[0]);
    }

    #[Test]
    public function showsCommandHelpWhenSubcommandNotFound(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $subs = [
            ['name' => 'ADD', 'desc_key' => 'ignore.add.short', 'help_key' => 'ignore.add.help', 'syntax_key' => 'ignore.add.syntax'],
        ];
        $targetCmd = $this->createDummyCommand('IGNORE', 1, $subs);

        $replies = [];
        $context = $this->createContext($sender, null, ['IGNORE', 'UNKNOWN_SUB'], $replies, [$targetCmd, $command]);
        $command->execute($context);

        self::assertNotEmpty($replies);
        self::assertStringContainsString('IGNORE', $replies[0]);
    }
}
