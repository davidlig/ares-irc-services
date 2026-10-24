<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Help;

use App\NickServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\NickServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\NickServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function count;
use function dirname;
use function end;
use function in_array;
use function is_array;
use function str_contains;

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
            'error_marker' => ['07'],
            'warning_marker' => ['04'],
            'intro_expiration' => ['04'],
            'general_header' => [],
            'examples_header' => [],
            'command_line' => ['03'],
            'subcommand_line' => ['03'],
            'options_header' => [],
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
        $expectedExamples = [
            'register' => ['example', 'examples'],
            'verify' => ['example'],
            'resend' => ['example'],
            'recover' => ['example', 'examples'],
            'identify' => ['example', 'native_example', 'native_examples'],
            'status' => ['example'],
            'info' => ['example'],
            'set' => ['example', 'examples'],
            'list' => ['example', 'examples'],
            'restore' => ['example'],
            'whoip' => ['example'],
        ];

        foreach ($locales as $locale) {
            $catalog = Yaml::parseFile($root . '/translations/nickserv.' . $locale . '.yaml');
            self::assertIsArray($catalog, $locale);
            self::assertIsArray($catalog['help'] ?? null, $locale);
            $help = $catalog['help'];

            foreach ($expectedColors as $keyPath => $expected) {
                $value = $help;
                foreach (explode('.', $keyPath) as $part) {
                    $value = is_array($value) ? ($value[$part] ?? null) : null;
                }
                self::assertIsString($value, $locale . ': help.' . $keyPath);
                preg_match_all('/\x03(\d{2})/', $value, $matches);
                self::assertSame($expected, $matches[1], $locale . ': help.' . $keyPath);
                self::assertSame(
                    count($matches[1]),
                    substr_count($value, "\x03\x0F") + substr_count($value, "\x03\x1F"),
                    $locale . ': each colored fragment must reset color and formatting',
                );
            }

            foreach ($expectedExamples as $command => $keys) {
                $commandTranslations = $catalog[$command] ?? null;
                self::assertIsArray($commandTranslations, $locale . ': ' . $command);
                foreach ($keys as $key) {
                    $examples = $commandTranslations[$key] ?? null;
                    $examples = is_array($examples) ? $examples : [$examples];
                    self::assertNotEmpty($examples, $locale . ': ' . $command . '.' . $key);
                    foreach ($examples as $example) {
                        self::assertIsString($example, $locale . ': ' . $command . '.' . $key);
                        if (!('identify' === $command && in_array($key, ['native_example', 'native_examples'], true))) {
                            self::assertStringContainsString('%command_prefix%', $example, $locale . ': ' . $command . '.' . $key);
                        }
                    }
                }
            }

            self::assertIsString($help['header']);
            self::assertStringStartsWith("\x02\x0307🤖 %title%\x03\x0F ", $help['header'], $locale);
            foreach (['general_header', 'options_header', 'examples_header', 'ircop_header'] as $section) {
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
            self::assertSame(
                count($allColors[1]),
                substr_count($helpText, "\x03\x0F") + substr_count($helpText, "\x03\x1F"),
                $locale . ': every HELP color must reset',
            );
            self::assertSame([], array_diff($allColors[1], ['03', '04', '07', '10', '14']), $locale);
            self::assertCount(2, array_filter($allColors[1], static fn (string $color): bool => '04' === $color), $locale . ': red is reserved for the warning icon and expiration duration');
        }

        $commandSource = file_get_contents($root . '/src/NickServ/Adapter/In/Irc/Command/HelpCommand.php');
        self::assertIsString($commandSource);
        foreach (['IrcHelpStyle', "\x03", '›', 'ℹ', '⚠', '✗', '◆'] as $presentationToken) {
            self::assertStringNotContainsString($presentationToken, $commandSource);
        }
    }

    #[Test]
    public function verificationMessagesUseDurationPlaceholdersInEveryLocale(): void
    {
        $root = dirname(__DIR__, 6);
        $locales = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];
        $messages = ['register.help', 'register.pending', 'verify.help', 'resend.help'];

        foreach ($locales as $locale) {
            $catalog = Yaml::parseFile($root . '/translations/nickserv.' . $locale . '.yaml');
            self::assertIsArray($catalog, $locale);

            foreach ($messages as $path) {
                [$section, $message] = explode('.', $path);
                self::assertIsArray($catalog[$section] ?? null, $locale . ': ' . $section);
                $value = $catalog[$section][$message] ?? null;
                self::assertIsString($value, $locale . ': ' . $path);
                self::assertStringContainsString('%duration%', $value, $locale . ': ' . $path);
            }
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
            $catalog = Yaml::parseFile($root . '/translations/nickserv.' . $locale . '.yaml');
            self::assertIsArray($catalog, $locale);
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

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context);

        self::assertSame([
            'translated:help.header',
            'translated:help.intro',
            ' ',
            'translated:help.general_header',
            ' ',
            'translated:help.group_header',
            'translated:help.command_line',
            ' ',
            'translated:help.general_footer',
            ' ',
            'translated:help.ircop_header',
            ' ',
            'translated:help.subgroup_header',
            'translated:help.command_line',
            'translated:help.command_line',
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

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context);

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
        ]], ['service' => 'NickServ']);

        $lines = new UnifiedHelpFormatter()->renderCommandHelp($context, $command);

        self::assertSame([
            'translated:help.header',
            'translated:set.help',
            ' ',
            'translated:help.options_header',
            'translated:help.subcommand_line',
            ' ',
            'translated:help.set_sub_footer',
            ' ',
            'translated:help.syntax_label',
            'translated:help.footer',
        ], $lines);
        self::assertSame([], $context->replies);
        self::assertSame([], $context->rawReplies);

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'NickServ']], $context->translations);
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
    public function formatsConfiguredDurationBeforeTranslatingCommandHelp(): void
    {
        $context = new NickServHelpFormatterContext();
        $command = new NickServHelpableCommand('REGISTER', 1, helpParams: ['duration_seconds' => 300]);

        new UnifiedHelpFormatter()->renderCommandHelp($context, $command);

        self::assertSame([300], $context->formattedDurations);
        self::assertContains([
            'key' => 'register.help',
            'params' => ['duration' => '5 minutes'],
        ], $context->translations);
    }

    #[Test]
    public function rendersRootExamplesWithTheConfiguredUncoloredMessagePrefix(): void
    {
        $context = new NickServHelpFormatterContext();
        $context->botName = 'ConfiguredBot';
        $context->exampleTranslations = [
            'help.examples_header' => 'Examples:',
            'register.example' => "%command_prefix%\x02\x0303REGISTER FirstPassword\x03\x0F",
            'register.examples.0' => "%command_prefix%\x02\x0303REGISTER SecondPassword\x03\x0F",
        ];
        $command = new class implements HelpableCommandInterface {
            public function getName(): string
            {
                return 'REGISTER';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'register.short';
            }

            public function getSyntaxKey(): string
            {
                return 'register.syntax';
            }

            public function getHelpKey(): string
            {
                return 'register.help';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return false;
            }

            public function getExampleKey(): string
            {
                return 'register.example';
            }
        };

        $lines = new UnifiedHelpFormatter()->renderCommandHelp($context, $command);

        self::assertContains('Examples:', $lines);
        self::assertContains("  /msg ConfiguredBot \x02\x0303REGISTER FirstPassword\x03\x0F", $lines);
        self::assertContains("  /msg ConfiguredBot \x02\x0303REGISTER SecondPassword\x03\x0F", $lines);
    }

    #[Test]
    public function doesNotLookUpExamplesForCommandsWithoutTheOptionalGetter(): void
    {
        $context = new NickServHelpFormatterContext();
        $context->exampleTranslations = [
            'help.examples_header' => 'Examples:',
            'register.example' => '%command_prefix%REGISTER Password',
        ];

        $lines = new UnifiedHelpFormatter()->renderCommandHelp($context, new NickServHelpableCommand('REGISTER', 1));

        self::assertNotContains('Examples:', $lines);
        self::assertNotContains('help.examples_header', array_column($context->translations, 'key'));
    }

    #[Test]
    public function rendersSubcommandHelpWithOptionalDetails(): void
    {
        $context = new NickServHelpFormatterContext();

        $lines = new UnifiedHelpFormatter()->renderSubCommandHelp($context, 'SET', [
            'name' => 'EMAIL',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
            'options_key' => 'set.email.options',
        ]);

        self::assertSame([
            'translated:help.header',
            'translated:set.email.help',
            ' ',
            'translated:set.email.options',
            ' ',
            'translated:help.syntax_label',
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

    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $translations = [];

    /** @var list<int> */
    public array $formattedDurations = [];

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

        if ('help.examples_header' === $key || str_contains($key, '.example')) {
            return $key;
        }

        return 'translated:' . $key;
    }

    public function formatDuration(int $seconds): string
    {
        $this->formattedDurations[] = $seconds;

        return 300 === $seconds ? '5 minutes' : $seconds . ' seconds';
    }

    public function getBotName(): string
    {
        return $this->botName;
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
     * @param list<array{name: string, desc_key: string, help_key: string, syntax_key: string, options_key?: string}> $subcommands
     * @param array<string, mixed>                                                                                    $helpParams
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
