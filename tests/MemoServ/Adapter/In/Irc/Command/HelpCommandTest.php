<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Irc\Command\HelpCommand;
use App\MemoServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\MemoServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Tests\Shared\Translations\TranslationCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_fill;
use function array_last;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;
use function ksort;
use function str_replace;
use function strtr;

#[CoversClass(HelpCommand::class)]
#[CoversClass(UnifiedHelpFormatter::class)]
#[CoversClass(HelpFormatterContextAdapter::class)]
#[CoversClass(MemoServContext::class)]
final class HelpCommandTest extends TestCase
{
    #[Test]
    public function spanishHelpUsesSelectedStyleForStructuredSections(): void
    {
        $translation = $this->createStub(TranslatorInterface::class);
        $translation->method('trans')->willReturnCallback(static fn (string $id): string => match ($id) {
            'help.header_title' => 'MemoServ',
            'help.header' => '%title%',
            'help.general_header' => 'Comandos disponibles:',
            default => $id,
        });
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(components: [
            'header' => new Appearance(prefix: '[HEADER] '),
            'section' => new Appearance(prefix: '[SECTION] '),
        ])));
        $replies = [];
        $context = $this->createContext(new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip'), null, [], $replies, [], $translation, language: 'es', renderer: $renderer);
        new HelpCommand(new UnifiedHelpFormatter())->execute($context);
        self::assertStringContainsString('[HEADER] MemoServ', $replies[0]);
        self::assertStringContainsString('[SECTION] Comandos disponibles:', $replies[0]);
    }

    #[Test]
    public function spanishExamplesUseTheSelectedMarkerAndKeepTheBotPrefixCopyable(): void
    {
        $catalog = TranslationCatalog::parseFile(dirname(__DIR__, 6) . '/translations/memoserv.es.yaml');
        $entries = [];
        $flatten = static function (array $values, string $prefix = '') use (&$flatten, &$entries): void {
            foreach ($values as $key => $value) {
                $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
                if (is_array($value)) {
                    $flatten($value, $path);
                } elseif (is_string($value)) {
                    $entries[$path] = $value;
                }
            }
        };
        $flatten($catalog);
        $translation = $this->createStub(TranslatorInterface::class);
        $translation->method('trans')->willReturnCallback(static function (string $id, array $params = []) use ($entries): string {
            $mapped = str_replace('dummy.', 'send.', $id);

            return strtr($entries[$mapped] ?? $id, $params);
        });
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(components: [
            'marked_example' => new Appearance(prefix: '  > '),
            'command' => new Appearance(prefix: '[', suffix: ']'),
        ])));
        $replies = [];
        $context = $this->createContext(new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip'), null, ['SEND'], $replies, [$this->createDummyCommand('SEND')], $translation, language: 'es', renderer: $renderer);
        new HelpCommand(new UnifiedHelpFormatter())->execute($context);
        self::assertStringContainsString('  > /msg MemoServ [SEND Ana Hola, ¿puedes revisar el memo?]', $replies[0]);
        self::assertStringContainsString('  > /msg MemoServ [SEND #ayuda Aviso para el canal]', $replies[0]);
        self::assertStringNotContainsString('%command_prefix%', $replies[0]);
    }

    #[Test]
    public function englishExamplesUseTheSelectedMarkerAndKeepTheBotPrefixCopyable(): void
    {
        $catalog = TranslationCatalog::parseFile(dirname(__DIR__, 6) . '/translations/memoserv.en.yaml');
        $entries = [];
        $flatten = static function (array $values, string $prefix = '') use (&$flatten, &$entries): void {
            foreach ($values as $key => $value) {
                $path = '' === $prefix ? (string) $key : $prefix . '.' . $key;
                if (is_array($value)) {
                    $flatten($value, $path);
                } elseif (is_string($value)) {
                    $entries[$path] = $value;
                }
            }
        };
        $flatten($catalog);
        $translation = $this->createStub(TranslatorInterface::class);
        $translation->method('trans')->willReturnCallback(static function (string $id, array $params = []) use ($entries): string {
            $mapped = str_replace('dummy.', 'send.', $id);

            return strtr($entries[$mapped] ?? $id, $params);
        });
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(components: [
            'marked_example' => new Appearance(prefix: '  > '),
            'command' => new Appearance(prefix: '[', suffix: ']'),
        ])));
        $replies = [];
        $context = $this->createContext(new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip'), null, ['SEND'], $replies, [$this->createDummyCommand('SEND')], $translation, language: 'en', renderer: $renderer);
        new HelpCommand(new UnifiedHelpFormatter())->execute($context);
        self::assertStringContainsString('  > /msg MemoServ [SEND Ana Hi, can you check the memo?]', $replies[0]);
        self::assertStringContainsString('  > /msg MemoServ [SEND #help Notice for the channel]', $replies[0]);
        self::assertStringNotContainsString('%command_prefix%', $replies[0]);
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
        self::assertSame('help.example', $command->getExampleKey());
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

        self::assertCount(1, $replies);
        self::assertStringContainsString('help.header_title', $replies[0]);
        self::assertStringContainsString('─────────────────────────────', array_last(explode("\n", $replies[0])));
    }

    #[Test]
    public function hidesOperOnlyCommandHelpFromNonOperator(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());
        $replies = [];
        $context = $this->createContext(
            $sender,
            null,
            ['RESTRICTED'],
            $replies,
            [$this->createDummyCommand('RESTRICTED', operOnly: true)],
        );

        $command->execute($context);

        self::assertSame(['unknown_command [%command%: RESTRICTED]'], $replies);
    }

    #[Test]
    public function repliesUnknownCommandWhenCommandNotFound(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $replies = [];
        $context = $this->createContext($sender, null, ['NONEXISTENT'], $replies, [$command]);
        $command->execute($context);

        self::assertSame(['unknown_command [%command%: NONEXISTENT]'], $replies);
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

        self::assertCount(1, $replies);
        self::assertStringContainsString('SEND', $replies[0]);
    }

    #[Test]
    public function showsSubCommandHelpWhenSubcommandMatches(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $subs = [
            ['name' => 'ADD', 'desc_key' => 'ignore.add.short', 'help_key' => 'ignore.add.help', 'syntax_key' => 'ignore.add.syntax', 'example_key' => 'ignore.add.example'],
            ['name' => 'DEL', 'desc_key' => 'ignore.del.short', 'help_key' => 'ignore.del.help', 'syntax_key' => 'ignore.del.syntax', 'example_key' => 'ignore.del.example'],
        ];
        $targetCmd = $this->createDummyCommand('IGNORE', 1, $subs);

        $replies = [];
        $context = $this->createContext($sender, null, ['IGNORE', 'ADD'], $replies, [$targetCmd, $command]);
        $command->execute($context);

        self::assertCount(1, $replies);
        self::assertStringContainsString('IGNORE ADD', $replies[0]);
    }

    #[Test]
    public function rejectsUnknownSubcommand(): void
    {
        $sender = new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $subs = [
            ['name' => 'ADD', 'desc_key' => 'ignore.add.short', 'help_key' => 'ignore.add.help', 'syntax_key' => 'ignore.add.syntax', 'example_key' => 'ignore.add.example'],
        ];
        $targetCmd = $this->createDummyCommand('IGNORE', 1, $subs);

        $replies = [];
        $context = $this->createContext($sender, null, ['IGNORE', 'UNKNOWN_SUB'], $replies, [$targetCmd, $command]);
        $command->execute($context);

        self::assertSame(['unknown_help_option [%command%: IGNORE, %option%: UNKNOWN_SUB]'], $replies);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function helpArguments(): iterable
    {
        yield 'general' => [[]];
        yield 'command' => [['IGNORE']];
        yield 'subcommand' => [['IGNORE', 'ADD']];
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('helpArguments')]
    public function renderingFailureSendsNoPartialHelp(array $arguments): void
    {
        $failure = new RuntimeException('Translation failed at the final footer.');
        $translation = $this->createStub(TranslatorInterface::class);
        $translation->method('trans')->willReturnCallback(static function (string $key) use ($failure): string {
            if (in_array($key, ['help.general_footer', 'help.syntax_label'], true)) {
                throw $failure;
            }

            return $key;
        });
        $target = $this->createDummyCommand('IGNORE', subcommands: [[
            'name' => 'ADD',
            'desc_key' => 'ignore.add.short',
            'help_key' => 'ignore.add.help',
            'syntax_key' => 'ignore.add.syntax',
            'example_key' => 'ignore.add.example',
        ]]);
        $replies = [];
        $context = $this->createContext(
            new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip'),
            null,
            $arguments,
            $replies,
            [$target],
            $translation,
        );

        try {
            new HelpCommand(new UnifiedHelpFormatter())->execute($context);
            self::fail('The translation failure must propagate synchronously.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame([], $replies);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('helpArguments')]
    public function transportFailurePropagatesSynchronously(array $arguments): void
    {
        $failure = new RuntimeException('Connection write failed.');
        $target = $this->createDummyCommand('IGNORE', subcommands: [[
            'name' => 'ADD',
            'desc_key' => 'ignore.add.short',
            'help_key' => 'ignore.add.help',
            'syntax_key' => 'ignore.add.syntax',
            'example_key' => 'ignore.add.example',
        ]]);
        $replies = [];
        $context = $this->createContext(
            new SenderView('001ABC', 'TestUser', 'ident', 'host', 'cloak', 'ip'),
            null,
            $arguments,
            $replies,
            [$target],
            sendFailure: $failure,
        );

        try {
            new HelpCommand(new UnifiedHelpFormatter())->execute($context);
            self::fail('The send failure must propagate during HELP execution.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame([], $replies);
    }

    #[Test]
    public function helpCommandAndFormatterKeepStyleMarkupAndMarkersInTranslations(): void
    {
        foreach ([
            '/src/MemoServ/Adapter/In/Irc/Command/HelpCommand.php',
            '/src/MemoServ/Adapter/In/Irc/Help/UnifiedHelpFormatter.php',
        ] as $relativePath) {
            $source = file_get_contents(dirname(__DIR__, 6) . $relativePath);
            self::assertIsString($source);
            self::assertStringNotContainsString('IrcHelpStyle', $source);
            self::assertDoesNotMatchRegularExpression('/[›ℹ⚠✗●◆─]|\x02|\x03\d{0,2}|\x0F|\\\\x0[23]|\\\\x0F/u', $source);
        }
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
     * @param list<string>                       $args
     * @param string[]                           $replies
     * @param iterable<MemoServCommandInterface> $commands
     */
    private function createContext(
        ?SenderView $sender,
        ?MemoAccountView $senderAccount,
        array $args,
        array &$replies = [],
        iterable $commands = [],
        ?TranslatorInterface $translation = null,
        ?RuntimeException $sendFailure = null,
        string $language = 'en',
        ?IrcRenderer $renderer = null,
    ): MemoServContext {
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$replies, $sendFailure): void {
            if (null !== $sendFailure) {
                throw $sendFailure;
            }
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
            command: 'HELP',
            args: $args,
            notifier: $notifier,
            translator: $translation ?? $translator,
            language: $language,
            timezone: 'UTC',
            messageType: 'NOTICE',
            registry: new MemoServCommandRegistry($commands),
            serviceNicks: $this->createServiceNicks(),
            renderer: $renderer,
        );
    }

    /**
     * @param list<array{name: string, desc_key: string, help_key: string, syntax_key: string, example_key: string}> $subcommands
     */
    private function createDummyCommand(string $name, int $order = 1, array $subcommands = [], bool $operOnly = false): MemoServCommandInterface
    {
        return new class($name, $order, $subcommands, $operOnly) implements MemoServCommandInterface {
            /**
             * @param list<array{name: string, desc_key: string, help_key: string, syntax_key: string, example_key: string}> $subcommands
             */
            public function __construct(
                private string $name,
                private int $order,
                private array $subcommands,
                private bool $operOnly,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition($this->getSyntaxKey(), array_fill(0, $this->getMinArgs(), new Argument('argument')));
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

            public function getExampleKey(): string
            {
                return 'dummy.example';
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
             * @return list<array{name: string, desc_key: string, help_key: string, syntax_key: string, example_key: string}>
             */
            public function getSubCommandHelp(): array
            {
                return $this->subcommands;
            }

            public function isOperOnly(): bool
            {
                return $this->operOnly;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(MemoServContext $context): null
            {
                return null;
            }
        };
    }
}
