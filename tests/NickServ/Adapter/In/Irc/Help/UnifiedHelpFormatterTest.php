<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Help;

use App\NickServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\NickServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\NickServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\Tests\Shared\Translations\TranslationCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_map;
use function array_search;
use function array_slice;
use function array_values;
use function dirname;
use function end;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function str_ends_with;
use function strtolower;

#[CoversClass(UnifiedHelpFormatter::class)]
final class UnifiedHelpFormatterTest extends TestCase
{
    #[Test]
    public function nickServListShortDescriptionsOmitTheIrcopOnlyQualifierInEveryLocale(): void
    {
        $root = dirname(__DIR__, 6);
        $locales = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];
        $qualifiers = [
            'ca' => 'només IRCops',
            'de' => 'nur IRCops',
            'el' => 'μόνο για IRCops',
            'en' => 'IRC Operators only',
            'es' => 'solo IRCops',
            'eu' => 'IRCopsentzat bakarrik',
            'fr' => 'IRCops uniquement',
            'gl' => 'só para IRCops',
            'it' => 'solo IRCop',
            'nl' => 'alleen IRCops',
            'pl' => 'tylko IRCop',
            'pt' => 'apenas IRCops',
            'ro' => 'numai IRCops',
            'tr' => 'yalnızca IRCop',
        ];

        foreach ($locales as $locale) {
            $catalog = TranslationCatalog::parseFile($root . '/translations/nickserv.' . $locale . '.yaml');
            self::assertIsArray($catalog['list'] ?? null, $locale . ': list');
            $short = $catalog['list']['short'] ?? null;
            self::assertIsString($short, $locale . ': list.short');
            self::assertStringNotContainsString($qualifiers[$locale], $short, $locale . ': list.short');
        }
    }

    #[Test]
    public function rendersFilteredGeneralHelpAndSortedIrcopSection(): void
    {
        $context = new NickServHelpFormatterContext(
            commands: [
                new NickServHelpableCommand('HIDDEN', 2),
                new NickServHelpableCommand('VISIBLE', 1),
                new NickServHelpableCommand('HELP', 0),
            ],
            ircopCommands: [
                new NickServHelpableCommand('LATE', 9),
                new NickServHelpableCommand('EARLY', 1),
            ],
            visibleCommands: ['VISIBLE'],
            ircopAccess: true,
        );

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context, 'translated:help.intro_expiration');

        self::assertSame([
            'translated:help.header',
            ' ',
            'translated:help.intro',
            ' ',
            ' ',
            'translated:help.general_header',
            ' ',
            'translated:help.group_header',
            'translated:help.command_line',
            ' ',
            'translated:help.intro_expiration',
            ' ',
            'translated:help.ircop_header',
            ' ',
            'translated:help.subgroup_header',
            'translated:help.command_line',
            'translated:help.command_line',
            ' ',
            ' ',
            'translated:help.general_footer',
        ], $lines);
        self::assertSame([], $context->replies);
        self::assertSame([], $context->rawReplies);

        $renderedCommands = [];
        foreach ($context->translations as $reply) {
            if ('help.command_line' !== $reply['key']) {
                continue;
            }

            self::assertIsString($reply['params']['command']);
            $renderedCommands[] = $reply['params']['command'];
        }
        self::assertSame([
            'VISIBLE     ',
            'EARLY       ',
            'LATE        ',
        ], $renderedCommands);
        $commandLines = array_values(array_filter(
            $context->translations,
            static fn (array $reply): bool => 'help.command_line' === $reply['key'],
        ));
        self::assertSame(
            ['translated:help.navigation_marker', 'translated:help.navigation_marker', 'translated:help.navigation_marker'],
            array_column(array_column($commandLines, 'params'), 'marker'),
        );
        self::assertContains([
            'key' => 'help.header',
            'params' => ['title' => 'translated:help.header_title'],
        ], $context->translations);
        self::assertContains('translated:help.general_header', $lines);
        self::assertContains('translated:help.ircop_header', $lines);
        self::assertContains('translated:help.group_header', $lines);
        self::assertContains('translated:help.subgroup_header', $lines);
        self::assertContains([
            'key' => 'help.general_footer',
            'params' => [
                'marker' => 'translated:help.info_marker',
                'syntax' => 'translated:help.general_syntax',
            ],
        ], $context->translations);
        self::assertContains(['key' => 'help.general_syntax', 'params' => []], $context->translations);
    }

    #[Test]
    public function rendersUngroupedCommandAndGeneralFooterWithoutAdminCommands(): void
    {
        $command = new NickServHelpableCommand('FUTURE', 1);
        $context = new NickServHelpFormatterContext(
            commands: [$command],
            visibleCommands: ['FUTURE'],
        );

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context, 'translated:help.intro_expiration');

        self::assertSame([' ', ' ', 'translated:help.general_footer'], array_slice($lines, -3));

        $keys = array_column($context->translations, 'key');
        self::assertContains('help.general_footer', $keys);
        self::assertContains([
            'key' => 'help.general_footer',
            'params' => [
                'marker' => 'translated:help.info_marker',
                'syntax' => 'translated:help.general_syntax',
            ],
        ], $context->translations);
        self::assertNotContains('help.ircop_header', $keys);
        $commandLines = array_values(array_filter(
            $context->translations,
            static fn (array $reply): bool => 'help.command_line' === $reply['key'],
        ));
        self::assertGreaterThan(
            array_search('translated:help.command_line', $lines, true),
            array_search('translated:help.intro_expiration', $lines, true),
        );
        self::assertSame(['FUTURE      '], array_column(array_column($commandLines, 'params'), 'command'));
        self::assertSame(['translated:help.navigation_marker'], array_column(array_column($commandLines, 'params'), 'marker'));
        self::assertSame(['translated:future.short'], array_column(array_column($commandLines, 'params'), 'description'));
    }

    #[Test]
    public function rendersCommandHelpWithParametersAndOptions(): void
    {
        $context = new NickServHelpFormatterContext();
        $command = new NickServHelpableCommand('SET', 1, [[
            'name' => 'EMAIL',
            'desc_key' => 'set.email.short',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
            'example_key' => 'set.email.example',
        ]], ['service' => 'NickServ', 'duration_seconds' => 300]);

        $lines = new UnifiedHelpFormatter()->renderCommandHelp($context, $command);

        self::assertSame([
            'translated:help.header',
            ' ',
            'translated:set.help',
            ' ',
            'translated:help.options_header',
            'translated:help.subcommand_line',
            ' ',
            'translated:help.set_sub_footer',
            ' ',
            'translated:help.syntax_label',
            ' ',
            'translated:help.examples_header',
            'translated:set.example',
            'translated:help.footer',
        ], $lines);
        self::assertSame([], $context->replies);
        self::assertSame([], $context->rawReplies);

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'NickServ', 'duration' => 'formatted:300']], $context->translations);
        self::assertSame([300], $context->formattedDurations);
        self::assertContains([
            'key' => 'help.subcommand_line',
            'params' => [
                'marker' => 'translated:help.navigation_marker',
                'command' => 'EMAIL     ',
                'description' => 'translated:set.email.short',
            ],
        ], $context->translations);
        self::assertContains([
            'key' => 'help.syntax_label',
            'params' => ['syntax' => 'translated:set.syntax'],
        ], $context->translations);
        self::assertContains(['key' => 'help.examples_header', 'params' => []], $context->translations);
        self::assertContains(['key' => 'set.example', 'params' => []], $context->translations);
        self::assertContains([
            'key' => 'help.set_sub_footer',
            'params' => [
                'marker' => 'translated:help.info_marker',
                'syntax' => 'translated:help.set_sub_syntax',
            ],
        ], $context->translations);
        self::assertContains([
            'key' => 'help.set_sub_syntax',
            'params' => ['command' => 'SET'],
        ], $context->translations);
        self::assertContains('translated:help.options_header', $lines);
    }

    #[Test]
    public function addsConfiguredUncoloredMessagePrefixToCommandOnlyExamples(): void
    {
        $context = new NickServHelpFormatterContext();
        $context->botName = 'ConfiguredBot';
        $context->exampleTranslations['test.examples.0'] = "%command_prefix%\x02\x0303HELP\x03\x0F";
        $context->exampleTranslations['test.example'] = "%command_prefix%\x02\x0303REGISTER Clave\$egura_123\x03\x0F";
        $command = new NickServHelpableCommand('TEST', 1, [], ['service' => 'NickServ']);

        $lines = new UnifiedHelpFormatter()->renderCommandHelp($context, $command);

        self::assertContains('  /msg ConfiguredBot ' . "\x02\x0303REGISTER Clave\$egura_123\x03\x0F", $lines);
        $firstExample = array_search('  /msg ConfiguredBot ' . "\x02\x0303REGISTER Clave\$egura_123\x03\x0F", $lines, true);
        self::assertIsInt($firstExample);
        self::assertSame("  /msg ConfiguredBot \x02\x0303HELP\x03\x0F", $lines[$firstExample + 1]);
    }

    #[Test]
    public function omitsExampleSectionWhenTheCurrentLocaleHasNoExampleTranslations(): void
    {
        $context = new NickServHelpFormatterContext(missingExampleTranslations: true);

        $commandLines = new UnifiedHelpFormatter()->renderCommandHelp($context, new NickServHelpableCommand('SET', 1, [[
            'name' => 'EMAIL',
            'desc_key' => 'set.email.short',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
            'example_key' => 'set.email.example',
        ]]));
        $subcommandLines = new UnifiedHelpFormatter()->renderSubCommandHelp($context, 'SET', [
            'name' => 'EMAIL',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
            'example_key' => 'set.email.example',
        ]);

        self::assertNotContains('help.examples_header', $commandLines);
        self::assertNotContains('set.example', $commandLines);
        self::assertNotContains('help.examples_header', $subcommandLines);
        self::assertNotContains('set.email.example', $subcommandLines);
    }

    #[Test]
    public function preservesSubcommandParagraphRowsWithoutChangingCommandHelp(): void
    {
        $context = new NickServHelpFormatterContext();
        $body = "Purpose.\n\n\x02 ■ Options:\x02\n\n\n    Indented explanation.\n";
        $context->exampleTranslations['test.help'] = $body;
        $context->exampleTranslations['test.options'] = "First detail.\n\nSecond detail.";
        $formatter = new UnifiedHelpFormatter();

        $lines = $formatter->renderSubCommandHelp($context, 'TEST', [
            'name' => 'ACTION',
            'help_key' => 'test.help',
            'syntax_key' => 'test.syntax',
            'example_key' => 'test.example',
            'options_key' => 'test.options',
        ]);

        self::assertSame("Purpose.\n \n\x02 ■ Options:\x02\n \n \n    Indented explanation.\n ", $lines[2]);
        self::assertContains("First detail.\n \nSecond detail.", $lines);
        self::assertSame($body, $formatter->renderCommandHelp($context, new NickServHelpableCommand('TEST', 1))[2]);
    }

    #[Test]
    public function preservesLocalizedSubcommandParagraphsInEveryLocale(): void
    {
        foreach (['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'] as $locale) {
            $catalog = TranslationCatalog::parseFile(dirname(__DIR__, 6) . '/translations/nickserv.' . $locale . '.yaml');
            $command = $catalog['set'] ?? null;
            self::assertIsArray($command, $locale);
            $subcommand = $command['vhost'] ?? null;
            self::assertIsArray($subcommand, $locale);
            $body = $subcommand['help'] ?? null;
            self::assertIsString($body, $locale);
            $context = new NickServHelpFormatterContext();
            $context->exampleTranslations['test.help'] = $body;

            $lines = new UnifiedHelpFormatter()->renderSubCommandHelp($context, 'SET', [
                'name' => 'VHOST',
                'help_key' => 'test.help',
                'syntax_key' => 'test.syntax',
                'example_key' => 'test.example',
            ]);

            self::assertSame(
                implode("\n", array_map(static fn (string $line): string => '' === $line ? ' ' : $line, explode("\n", $body))),
                $lines[2],
                $locale,
            );
        }
    }

    #[Test]
    public function rendersSubcommandHelpWithOptionalDetails(): void
    {
        $context = new NickServHelpFormatterContext();

        $lines = new UnifiedHelpFormatter()->renderSubCommandHelp($context, 'SET', [
            'name' => 'EMAIL',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
            'example_key' => 'set.email.example',
            'options_key' => 'set.email.options',
        ]);

        self::assertSame([
            'translated:help.header',
            ' ',
            'translated:set.email.help',
            ' ',
            'translated:help.syntax_label',
            ' ',
            'translated:help.examples_header',
            'translated:set.email.example',
            ' ',
            'translated:set.email.options',
            'translated:help.footer',
        ], $lines);
        self::assertSame([], $context->replies);
        self::assertSame([], $context->rawReplies);
        self::assertContains([
            'key' => 'help.header',
            'params' => ['title' => 'HELP SET EMAIL'],
        ], $context->translations);
        $rawReplies = $lines;
        self::assertSame('translated:help.footer', end($rawReplies));
    }
}

final class NickServHelpFormatterContext implements HelpFormatterContextInterface
{
    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $replies = [];

    /** @var list<string> */
    public array $rawReplies = [];

    /** @var list<int> */
    public array $formattedDurations = [];

    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $translations = [];

    /** @var array<string, string> */
    public array $exampleTranslations = [];

    public string $botName = 'NickServ';

    /**
     * @param list<HelpableCommandInterface> $commands
     * @param list<HelpableCommandInterface> $ircopCommands
     * @param list<string>                   $visibleCommands
     */
    public function __construct(
        private readonly array $commands = [],
        private readonly array $ircopCommands = [],
        private readonly array $visibleCommands = [],
        private readonly bool $ircopAccess = false,
        private readonly bool $missingExampleTranslations = false,
    ) {}

    public function reply(string $key, array $params = []): void
    {
        $this->replies[] = ['key' => $key, 'params' => $params];
    }

    public function replyRaw(string $message): void
    {
        $this->rawReplies[] = $message;
    }

    public function trans(string $key, array $params = []): string
    {
        $this->translations[] = ['key' => $key, 'params' => $params];

        if (isset($this->exampleTranslations[$key])) {
            return $this->exampleTranslations[$key];
        }

        if (1 === preg_match('/\.examples\.\d+$/', $key)) {
            return $key;
        }

        if ($this->missingExampleTranslations && ('help.examples_header' === $key || str_ends_with($key, '.example'))) {
            return $key;
        }

        return 'translated:' . $key;
    }

    public function getBotName(): string
    {
        return $this->botName;
    }

    public function formatDuration(int $seconds): string
    {
        $this->formattedDurations[] = $seconds;

        return 'formatted:' . $seconds;
    }

    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->commands;
    }

    public function shouldShowCommandInGeneralHelp(HelpableCommandInterface $command): bool
    {
        return in_array($command->getName(), $this->visibleCommands, true);
    }

    public function canViewCommandInHelp(HelpableCommandInterface $command): bool
    {
        return $this->shouldShowCommandInGeneralHelp($command) || in_array($command, $this->ircopCommands, true);
    }

    public function getHelpGroups(): array
    {
        return [
            ['group_key' => 'help.group.public', 'commands' => ['VISIBLE', 'HIDDEN'], 'admin' => false, 'subgroup' => false],
            ['group_key' => 'help.ircop_group.operations', 'commands' => ['EARLY', 'LATE'], 'admin' => true, 'subgroup' => true],
        ];
    }

    public function getIrcopCommands(): iterable
    {
        return $this->ircopCommands;
    }

    public function hasIrcopAccess(): bool
    {
        return $this->ircopAccess;
    }
}

final readonly class NickServHelpableCommand implements HelpableCommandInterface
{
    /**
     * @param list<array{name: string, desc_key: string, help_key: string, syntax_key: string, example_key: string, options_key?: string}> $subcommands
     * @param array<string, mixed>                                                                                                         $helpParams
     */
    public function __construct(
        private string $name,
        private int $order,
        private array $subcommands = [],
        private array $helpParams = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getOrder(): int
    {
        return $this->order;
    }

    public function getShortDescKey(): string
    {
        return strtolower($this->name) . '.short';
    }

    public function getSyntaxKey(): string
    {
        return strtolower($this->name) . '.syntax';
    }

    public function getHelpKey(): string
    {
        return strtolower($this->name) . '.help';
    }

    public function getExampleKey(): string
    {
        return strtolower($this->name) . '.example';
    }

    public function getSubCommandHelp(): array
    {
        return $this->subcommands;
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function getHelpParams(): array
    {
        return $this->helpParams;
    }
}
