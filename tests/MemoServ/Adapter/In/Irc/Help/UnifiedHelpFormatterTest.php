<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Help;

use App\MemoServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\MemoServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\MemoServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function in_array;
use function is_array;
use function is_scalar;
use function is_string;

#[CoversClass(UnifiedHelpFormatter::class)]
final class UnifiedHelpFormatterTest extends TestCase
{
    #[Test]
    public function rendersFilteredGroupedGeneralHelp(): void
    {
        $context = new MemoServHelpFormatterContext(
            commands: [
                new MemoServHelpableCommand('HIDDEN', 2),
                new MemoServHelpableCommand('VISIBLE', 1),
                new MemoServHelpableCommand('HELP', 0),
            ],
            visibleCommands: ['VISIBLE'],
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
        ], $lines);

        $renderedCommands = [];
        foreach ($context->translationRequests as $reply) {
            if ('help.command_line' !== $reply['key']) {
                continue;
            }

            self::assertIsString($reply['params']['command']);
            $renderedCommands[] = $reply['params']['command'];
        }
        self::assertSame(['VISIBLE     '], $renderedCommands);
        self::assertNotContains('help.ircop_header', array_column($context->translationRequests, 'key'));
        self::assertSame('help.header', $context->translationRequests[1]['key']);
        self::assertSame('MemoServ', $context->translationRequests[1]['params']['title']);
        self::assertContains('help.group_header', array_column($context->translationRequests, 'key'));
    }

    #[Test]
    public function rendersAdminSubgroupAndUngroupedCommand(): void
    {
        $visible = new MemoServHelpableCommand('VISIBLE', 1);
        $ungrouped = new MemoServHelpableCommand('FUTURE', 2);
        $context = new MemoServHelpFormatterContext(
            commands: [$visible, $ungrouped],
            visibleCommands: ['VISIBLE', 'FUTURE'],
            helpGroups: [
                ['group_key' => 'help.group.empty', 'commands' => ['MISSING'], 'admin' => false, 'subgroup' => false],
                ['group_key' => 'help.ircop_group.operations', 'commands' => ['VISIBLE'], 'admin' => true, 'subgroup' => true],
            ],
        );

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context);

        self::assertSame([
            'translated:help.header',
            'translated:help.intro',
            ' ',
            'translated:help.general_header',
            ' ',
            'translated:help.general_footer',
            ' ',
            'translated:help.ircop_header',
            ' ',
            'translated:help.subgroup_header',
            'translated:help.command_line',
            'translated:help.command_line',
        ], $lines);

        $keys = array_column($context->translationRequests, 'key');
        self::assertContains('help.general_footer', $keys);
        self::assertContains('help.ircop_header', $keys);
        self::assertContains('help.subgroup_header', $keys);
        $commandLines = array_values(array_filter(
            $context->translationRequests,
            static fn (array $reply): bool => 'help.command_line' === $reply['key'],
        ));
        self::assertSame(['VISIBLE     ', 'FUTURE      '], array_column(array_column($commandLines, 'params'), 'command'));
    }

    #[Test]
    public function sortsAndDeduplicatesGroupedCommandsAndPreservesUngroupedFallback(): void
    {
        $context = new MemoServHelpFormatterContext(
            commands: [
                new MemoServHelpableCommand('LATE', 2),
                new MemoServHelpableCommand('EARLY', 1),
                new MemoServHelpableCommand('FUTURE', 3),
            ],
            visibleCommands: ['LATE', 'EARLY', 'FUTURE'],
            helpGroups: [
                ['group_key' => 'help.group.commands', 'commands' => ['late', 'EARLY', 'LATE'], 'admin' => false, 'subgroup' => false],
                ['group_key' => 'help.group.duplicate', 'commands' => ['EARLY', 'LATE'], 'admin' => false, 'subgroup' => false],
            ],
        );

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context);

        $commandRequests = array_values(array_filter(
            $context->translationRequests,
            static fn (array $request): bool => 'help.command_line' === $request['key'],
        ));
        self::assertSame(['EARLY       ', 'LATE        ', 'FUTURE      '], array_column(array_column($commandRequests, 'params'), 'command'));
        self::assertCount(1, array_filter($lines, static fn (string $line): bool => 'translated:help.group_header' === $line));
        self::assertSame('translated:help.command_line', array_last($lines));
    }

    #[Test]
    public function rendersCommandHelpWithParametersAndOptions(): void
    {
        $context = new MemoServHelpFormatterContext();
        $command = new MemoServHelpableCommand('SET', 1, [[
            'name' => 'EMAIL',
            'desc_key' => 'set.email.short',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
        ]], ['service' => 'MemoServ']);

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

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'MemoServ']], $context->translationRequests);
        self::assertContains([
            'key' => 'help.subcommand_line',
            'params' => ['command' => 'EMAIL     ', 'description' => 'translated:set.email.short'],
        ], $context->translationRequests);
        self::assertContains([
            'key' => 'help.syntax_label',
            'params' => ['syntax' => 'translated:set.syntax'],
        ], $context->translationRequests);
    }

    #[Test]
    public function rendersSubcommandHelpWithOptionalDetails(): void
    {
        $context = new MemoServHelpFormatterContext();

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
        self::assertSame('HELP SET EMAIL', $context->translationRequests[0]['params']['title']);
    }

    #[Test]
    public function rendersCanonicalColorsFromAllMemoServTranslationCatalogs(): void
    {
        foreach (['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'] as $locale) {
            $catalog = $this->requireStringKeyedArray(
                Yaml::parseFile(dirname(__DIR__, 6) . '/translations/memoserv.' . $locale . '.yaml'),
                $locale . ' catalog must parse as a mapping',
            );

            $helpTranslations = $catalog['help'] ?? null;
            self::assertIsArray($helpTranslations, $locale);
            self::assertSame('%bot%', $helpTranslations['header_title'] ?? null, $locale);
            self::assertArrayHasKey('header', $helpTranslations, $locale);
            foreach ($this->flattenStrings($helpTranslations) as $translation) {
                preg_match_all('/\x03(\d{1,2})/', $translation, $matches);
                foreach ($matches[1] as $color) {
                    self::assertContains($color, ['03', '07', '10', '14'], $locale . ' uses non-canonical HELP color ' . $color);
                }
            }

            foreach (['general_header', 'options_header', 'ircop_header'] as $section) {
                self::assertIsString($helpTranslations[$section]);
                self::assertStringStartsWith("\x02 ■ ", $helpTranslations[$section], $locale);
                self::assertStringEndsWith("\x02", $helpTranslations[$section], $locale);
                self::assertStringNotContainsString("\x03", $helpTranslations[$section], $locale);
            }
            foreach (['group_header', 'subgroup_header'] as $group) {
                self::assertSame("\x0310  ◆ %group%\x03\x0F", $helpTranslations[$group], $locale);
            }
            foreach (['command_line', 'subcommand_line'] as $row) {
                self::assertSame("    \x0310›\x03\x0F \x02\x0303%command%\x03\x0F%description%", $helpTranslations[$row], $locale);
            }

            $command = new MemoServHelpableCommand('IGNORE', 1, [[
                'name' => 'ADD',
                'desc_key' => 'ignore.add.short',
                'help_key' => 'ignore.add.help',
                'syntax_key' => 'ignore.add.syntax',
            ]]);
            $context = new MemoServHelpFormatterContext(
                commands: [$command],
                visibleCommands: ['IGNORE'],
                helpGroups: [[
                    'group_key' => 'help.group.messages',
                    'commands' => ['IGNORE'],
                    'admin' => false,
                    'subgroup' => false,
                ]],
                translations: $catalog,
            );
            $formatter = new UnifiedHelpFormatter();
            $lines = [...$formatter->renderGeneralHelp($context), ...$formatter->renderCommandHelp($context, $command)];

            foreach ($lines as $renderedReply) {
                self::assertDoesNotMatchRegularExpression('/%[^%]+%/', $renderedReply, $locale);
            }

            self::assertStringContainsString("\x02\x0307🤖 MemoServ\x03\x0F", $context->renderedTranslations[1], $locale);
            self::assertStringContainsString("\x0314─────────────────────────────\x03\x0F", $context->renderedTranslations[1], $locale);

            $commandRow = $this->renderedReplyFor($context, 'help.command_line');
            self::assertMatchesRegularExpression('/^    \x0310›\x03\x0F \x02\x0303IGNORE\s+\x03\x0F.+/u', $commandRow, $locale);

            $generalFooter = $this->renderedReplyFor($context, 'help.general_footer');
            self::assertMatchesRegularExpression('/\x0310ℹ\x03\x0F.*\/msg MemoServ \x02\x0303HELP.*\x03\x0F/u', $generalFooter, $locale);

            $subcommandFooter = $this->renderedReplyFor($context, 'help.set_sub_footer');
            self::assertMatchesRegularExpression('/\x0310ℹ\x03\x0F.*\/msg MemoServ \x02\x0303HELP IGNORE <[^>]+>\x03\x0F/u', $subcommandFooter, $locale);

            $syntax = $this->renderedReplyFor($context, 'help.syntax_label');
            self::assertMatchesRegularExpression('/^[^\x02\x03]+ \x02\x0303.+\x03\x0F$/u', $syntax, $locale);

            $formatter->renderSubCommandHelp($context, 'IGNORE', [
                'name' => 'ADD',
                'help_key' => 'ignore.add.help',
                'syntax_key' => 'ignore.add.syntax',
            ]);
            $subcommandHeader = null;
            foreach ($context->translationRequests as $index => $reply) {
                if ('help.header' === $reply['key'] && 'HELP IGNORE ADD' === $reply['params']['title']) {
                    $subcommandHeader = $context->renderedTranslations[$index];
                    break;
                }
            }
            self::assertIsString($subcommandHeader, $locale);
            self::assertStringContainsString("\x02\x0307🤖 HELP IGNORE ADD\x03\x0F", $subcommandHeader, $locale);
        }
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    private function flattenStrings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                array_push($strings, ...$this->flattenStrings($value));
            } elseif (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireStringKeyedArray(mixed $value, string $message): array
    {
        self::assertIsArray($value, $message);

        $entries = [];
        foreach ($value as $key => $entry) {
            self::assertIsString($key, $message);
            $entries[$key] = $entry;
        }

        return $entries;
    }

    private function renderedReplyFor(MemoServHelpFormatterContext $context, string $key): string
    {
        foreach ($context->translationRequests as $index => $reply) {
            if ($key === $reply['key']) {
                return $context->renderedTranslations[$index];
            }
        }

        self::fail('No reply found for ' . $key);
    }
}

final class MemoServHelpFormatterContext implements HelpFormatterContextInterface
{
    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $translationRequests = [];

    /** @var list<string> */
    public array $renderedTranslations = [];

    /**
     * @param list<HelpableCommandInterface>                                                           $commands
     * @param list<HelpableCommandInterface>                                                           $ircopCommands
     * @param list<string>                                                                             $visibleCommands
     * @param list<array{group_key: string, commands: list<string>, admin: bool, subgroup: bool}>|null $helpGroups
     * @param array<string, mixed>|null                                                                $translations
     */
    public function __construct(
        private readonly array $commands = [],
        private readonly array $ircopCommands = [],
        private readonly array $visibleCommands = [],
        private readonly bool $ircopAccess = false,
        private readonly ?array $helpGroups = null,
        private readonly ?array $translations = null,
    ) {}

    public function reply(string $key, array $params = []): void
    {
        throw new LogicException('Rendering must not send translated replies.');
    }

    public function replyRaw(string $message): void
    {
        throw new LogicException('Rendering must not send raw replies.');
    }

    public function trans(string $key, array $params = []): string
    {
        $this->translationRequests[] = ['key' => $key, 'params' => $params];
        $translation = $this->translate($key, $params);
        $this->renderedTranslations[] = $translation;

        return $translation;
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
        return $this->shouldShowCommandInGeneralHelp($command);
    }

    public function getHelpGroups(): array
    {
        return $this->helpGroups ?? [
            ['group_key' => 'help.group.messages', 'commands' => ['VISIBLE', 'HIDDEN'], 'admin' => false, 'subgroup' => false],
            ['group_key' => 'help.group.preferences', 'commands' => [], 'admin' => false, 'subgroup' => false],
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

    /** @param array<string, mixed> $params */
    private function translate(string $key, array $params = []): string
    {
        if (null === $this->translations) {
            $translation = 'help.header_title' === $key ? '%bot%' : 'translated:' . $key;
        } else {
            $translation = $this->translations;
            foreach (explode('.', $key) as $part) {
                if (!is_array($translation) || !isset($translation[$part])) {
                    return $key;
                }
                $translation = $translation[$part];
            }
        }
        if (!is_string($translation)) {
            return $key;
        }

        $replace = ['%bot%' => 'MemoServ', '%memoserv%' => 'MemoServ'];
        foreach ($params as $name => $value) {
            $replace['%' . trim((string) $name, '%') . '%'] = is_scalar($value) || $value instanceof Stringable ? (string) $value : '';
        }

        return strtr($translation, $replace);
    }
}

final readonly class MemoServHelpableCommand implements HelpableCommandInterface
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
