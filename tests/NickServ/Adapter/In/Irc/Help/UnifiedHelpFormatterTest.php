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
use function array_diff;
use function array_filter;
use function array_map;
use function array_search;
use function array_slice;
use function array_unique;
use function array_values;
use function array_walk_recursive;
use function count;
use function dirname;
use function end;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function preg_match_all;
use function serialize;
use function str_contains;
use function str_ends_with;
use function strtolower;
use function substr_count;

#[CoversClass(UnifiedHelpFormatter::class)]
final class UnifiedHelpFormatterTest extends TestCase
{
    #[Test]
    public function nickServHelpCatalogsUseOnlyTheApprovedResetPalette(): void
    {
        $root = dirname(__DIR__, 6);
        $locales = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];
        $expectedColors = [
            'header' => ['07', '14'],
            'navigation_marker' => ['10'],
            'info_marker' => ['10'],
            'error_marker' => ['04'],
            'warning_marker' => ['04'],
            'intro_expiration' => ['04'],
            'general_header' => [],
            'command_line' => ['03'],
            'subcommand_line' => ['03'],
            'options_header' => [],
            'examples_header' => [],
            'general_footer' => ['03'],
            'set_sub_footer' => ['03'],
            'syntax_label' => ['03'],
            'footer' => ['14'],
            'group_header' => ['10'],
            'subgroup_header' => ['10'],
            'ircop_header' => [],
            'set_timezone.index_label' => ['03'],
            'set_timezone.region_header' => [],
            'set_timezone.region_unknown' => ['03'],
        ];

        foreach ($locales as $locale) {
            $catalog = TranslationCatalog::parseFile($root . '/translations/nickserv.' . $locale . '.yaml');
            self::assertIsArray($catalog['help'] ?? null, $locale);
            $help = $catalog['help'];
            self::assertArrayNotHasKey('unknown_option', $help, $locale);

            $commonCatalog = TranslationCatalog::parseFile($root . '/translations/common.' . $locale . '.yaml');
            $unknownHelpOption = $commonCatalog['unknown_help_option'] ?? null;
            self::assertIsString($unknownHelpOption, $locale);
            preg_match_all('/\x03(\d{2})/', $unknownHelpOption, $commonColors);
            self::assertSame(['04', '03', '03', '03'], $commonColors[1], $locale . ': common.unknown_help_option');
            self::assertSame(4, substr_count($unknownHelpOption, "\x03") - count($commonColors[1]), $locale);

            foreach ($expectedColors as $keyPath => $expected) {
                $value = $help;
                foreach (explode('.', $keyPath) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                self::assertIsString($value, $locale . ': help.' . $keyPath);
                preg_match_all('/\x03(\d{2})/', $value, $matches);
                self::assertSame($expected, $matches[1], $locale . ': help.' . $keyPath);
                $resetCount = substr_count($value, "\x03") - count($matches[1]);
                self::assertSame(
                    count($matches[1]),
                    $resetCount,
                    $locale . ': each colored fragment must reset color and formatting',
                );
            }

            self::assertIsString($help['header']);
            self::assertStringStartsWith("\x02\x0307🤖 %title%\x03\x0F ", $help['header'], $locale);
            foreach (['general_header', 'options_header', 'ircop_header'] as $section) {
                self::assertIsString($help[$section]);
                self::assertStringStartsWith("\x02 ■ ", $help[$section], $locale . ': ' . $section);
                self::assertStringEndsWith("\x02", $help[$section], $locale . ': ' . $section);
            }
            foreach (['group_header', 'subgroup_header'] as $group) {
                self::assertSame("\x0310  ◆ %group%\x03\x0F", $help[$group], $locale);
            }
            foreach (['command_line', 'subcommand_line'] as $row) {
                self::assertSame("    %marker% \x02\x0303%command%\x03\x0F%description%", $help[$row], $locale);
            }
            self::assertIsString($help['intro_expiration']);
            self::assertMatchesRegularExpression('/\x1F\x0304%days% [^\x03]+\x03\x1F/u', $help['intro_expiration'], $locale . ': full localized duration is red and underlined');
            self::assertSame(2, substr_count($help['intro_expiration'], "\x1F"), $locale . ': underline cannot bleed into surrounding prose');

            $helpText = serialize($help);
            preg_match_all('/\x03(\d{2})/', $helpText, $allColors);
            self::assertNotEmpty($allColors[1], $locale);
            $colorResetCount = substr_count($helpText, "\x03") - count($allColors[1]);
            self::assertSame(
                count($allColors[1]),
                $colorResetCount,
                $locale . ': every HELP color must reset',
            );
            self::assertSame([], array_diff($allColors[1], ['03', '04', '07', '10', '14']), $locale);
            self::assertCount(3, array_filter($allColors[1], static fn (string $color): bool => '04' === $color), $locale . ': red is reserved for the error marker, warning marker and expiration duration');

            if ('es' === $locale) {
                $examples = [];
                array_walk_recursive($catalog, static function (mixed $value, int|string $key) use (&$examples): void {
                    if (is_string($value) && ('example' === $key || str_ends_with((string) $key, '_example'))) {
                        $examples[(string) $key] = $value;
                    }
                });
                self::assertNotEmpty($examples, 'Spanish command examples must be present.');
                foreach ($examples as $key => $example) {
                    preg_match_all('/\x03(\d{2})/', $example, $matches);
                    self::assertNotEmpty($matches[1], 'Each Spanish example must apply its command color: ' . $key);
                    self::assertSame(['03'], array_values(array_unique($matches[1])), 'Examples use the approved command color: ' . $key);
                    self::assertSame(
                        count($matches[1]),
                        substr_count($example, "\x03\x0F") + substr_count($example, "\x03\x1F"),
                        'Every example command color must reset: ' . $key,
                    );
                    if (str_contains($example, '/msg ')) {
                        self::assertDoesNotMatchRegularExpression('/\x02\x0303\/msg /', $example, 'Transport prefix must remain uncolored: ' . $key);
                        self::assertMatchesRegularExpression('/\/msg [^ ]+ \x02\x0303[A-Z]+/', $example, 'Service command must be highlighted: ' . $key);
                    }
                }
            }
        }

        $commandSource = file_get_contents($root . '/src/NickServ/Adapter/In/Irc/Command/HelpCommand.php');
        self::assertIsString($commandSource);
        foreach (['IrcHelpStyle', "\x03", '›', 'ℹ', '⚠', '✗', '◆'] as $presentationToken) {
            self::assertStringNotContainsString($presentationToken, $commandSource);
        }
    }

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
