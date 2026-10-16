<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc\Help;

use App\CRegServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\CRegServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\CRegServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;

use const STR_PAD_LEFT;

#[CoversClass(UnifiedHelpFormatter::class)]
final class UnifiedHelpFormatterTest extends TestCase
{
    private const array LOCALES = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];

    private const array ALLOWED_HELP_COLORS = ['03', '07', '10', '14'];

    #[Test]
    public function rendersFilteredGeneralHelpAndSortedIrcopSection(): void
    {
        $context = new CRegServHelpFormatterContext(
            commands: [
                new CRegServHelpableCommand('HIDDEN', 2),
                new CRegServHelpableCommand('VISIBLE', 1),
                new CRegServHelpableCommand('HELP', 0),
            ],
            ircopCommands: [
                new CRegServHelpableCommand('SAREGISTER', 9),
                new CRegServHelpableCommand('APROBAR', 1),
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
            'APROBAR     ',
            'SAREGISTER  ',
        ], $renderedCommands);
    }

    #[Test]
    public function rendersEachIrcopSubgroupAndItsCommands(): void
    {
        $groups = [
            ['group_key' => 'help.ircop_group.approval', 'commands' => ['APROBAR', 'DENEGAR'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.queue', 'commands' => ['PENDIENTES', 'DENEGADOS', 'DROP'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.forced', 'commands' => ['SAREGISTER'], 'admin' => true, 'subgroup' => true],
        ];
        $commandNames = array_merge(...array_column($groups, 'commands'));
        $commands = [];
        foreach ($commandNames as $order => $name) {
            $commands[] = new CRegServHelpableCommand($name, $order);
        }
        $context = new CRegServHelpFormatterContext(
            ircopCommands: $commands,
            ircopAccess: true,
            helpGroups: $groups,
        );

        new UnifiedHelpFormatter()->renderGeneralHelp($context);

        $translatedKeys = array_column($context->translations, 'key');
        $groupKeys = array_column($groups, 'group_key');
        self::assertSame($groupKeys, array_values(array_filter(
            $translatedKeys,
            static fn (string $key): bool => in_array($key, $groupKeys, true),
        )));
        self::assertCount(1, array_keys($translatedKeys, 'help.ircop_header', true));
    }

    #[Test]
    public function rendersUngroupedCommandAndGeneralFooterWithoutAdminCommands(): void
    {
        $context = new CRegServHelpFormatterContext(
            commands: [new CRegServHelpableCommand('VISIBLE', 1)],
            visibleCommands: ['VISIBLE'],
            helpGroups: [
                ['group_key' => 'help.group.requests', 'commands' => ['NOTHERE'], 'admin' => false, 'subgroup' => false],
            ],
        );

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context);

        self::assertSame(' ', $lines[4]);
        self::assertSame('translated:help.general_footer', $lines[5]);
        self::assertSame('translated:help.command_line', $lines[6]);
        self::assertCount(7, $lines);
        self::assertNotContains('translated:help.ircop_header', $lines);
    }

    #[Test]
    public function rendersCommandHelpWithParametersAndOptions(): void
    {
        $context = new CRegServHelpFormatterContext();

        $lines = new UnifiedHelpFormatter()->renderCommandHelp($context, new CRegServHelpableCommand('SET', 1, [[
            'name' => 'EMAIL',
            'desc_key' => 'set.email.short',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
        ]], ['command' => 'SET']));

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
        self::assertSame([], $context->rawReplies);
    }

    #[Test]
    public function rendersSubcommandHelpWithAndWithoutOptions(): void
    {
        $formatter = new UnifiedHelpFormatter();

        $withOptions = $formatter->renderSubCommandHelp(new CRegServHelpFormatterContext(), 'SET', [
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
        ], $withOptions);

        $withoutOptions = $formatter->renderSubCommandHelp(new CRegServHelpFormatterContext(), 'SET', [
            'name' => 'EMAIL',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
        ]);
        self::assertSame([
            'translated:help.header',
            'translated:set.email.help',
            ' ',
            'translated:help.syntax_label',
            'translated:help.footer',
        ], $withoutOptions);
    }

    #[Test]
    public function renderFooterReturnsTheTranslatedFooter(): void
    {
        $context = new CRegServHelpFormatterContext();

        self::assertSame('translated:help.footer', new UnifiedHelpFormatter()->renderFooter($context));
    }

    #[Test]
    public function definesHeaderTemplateAndPlaceholdersInEveryLocale(): void
    {
        $context = new CRegServHelpFormatterContext();
        $header = new UnifiedHelpFormatter()->renderHeader($context, 'HELP REGISTRA');

        self::assertSame('translated:help.header', $header);
        self::assertSame([], $context->replies);
        self::assertSame([], $context->rawReplies);

        self::assertSame([
            'key' => 'help.header',
            'params' => ['title' => 'HELP REGISTRA', 'separator' => str_repeat('─', 40 - 3 - mb_strlen('HELP REGISTRA'))],
        ], $context->translations[0]);

        $expectedTemplate = "\x02\x0307🤖 %title%\x03\x0F \x0314%separator%\x03\x0F";
        foreach (self::LOCALES as $locale) {
            $help = $this->loadHelpCatalog($locale);
            self::assertSame($expectedTemplate, $help['header'] ?? null, $locale);
        }
    }

    #[Test]
    public function allLocaleHelpTemplatesUseOnlyCanonicalStructuralColors(): void
    {
        foreach (self::LOCALES as $locale) {
            $help = $this->loadHelpCatalog($locale);
            $this->assertHelpColorsAllowed($help, $locale);

            foreach (['general_header', 'options_header', 'ircop_header'] as $section) {
                self::assertIsString($help[$section], $locale);
                self::assertStringStartsWith("\x02 ■ ", $help[$section], $locale);
                self::assertStringEndsWith("\x02", $help[$section], $locale);
            }
            foreach (['group_header', 'subgroup_header'] as $group) {
                self::assertSame("\x0310  ◆ %group%\x03\x0F", $help[$group], $locale);
            }
            foreach (['command_line', 'subcommand_line'] as $row) {
                self::assertSame("    \x0310›\x03\x0F \x02\x0303%command%\x03\x0F%description%", $help[$row], $locale);
            }
            foreach (['general_footer', 'set_sub_footer'] as $hint) {
                self::assertIsString($help[$hint], $locale);
                self::assertStringStartsWith("\x0310ℹ\x03\x0F ", $help[$hint], $locale);
                self::assertMatchesRegularExpression('/\x02\x0303HELP [^\x03]+\x03\x0F/u', $help[$hint], $locale);
            }
            self::assertSame("\x0314─────────────────────────────\x03\x0F", $help['footer'], $locale);
        }
    }

    #[Test]
    public function definesCRegServHelpGroupsInEveryLocale(): void
    {
        $expectedPublicGroups = ['requests'];
        $expectedIrcopGroups = ['approval', 'queue', 'forced'];

        foreach (self::LOCALES as $locale) {
            $help = $this->loadHelpCatalog($locale);
            $publicGroups = $this->requireStringKeyedArray($help['group'] ?? null, $locale . ' must define public HELP groups');
            $ircopGroups = $this->requireStringKeyedArray($help['ircop_group'] ?? null, $locale . ' must define IRCop HELP groups');
            self::assertSame($expectedPublicGroups, array_keys($publicGroups), $locale);
            self::assertSame($expectedIrcopGroups, array_keys($ircopGroups), $locale);
            foreach ($ircopGroups as $label) {
                self::assertIsString($label, $locale);
                self::assertNotSame('', trim($label), $locale);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadHelpCatalog(string $locale): array
    {
        $path = dirname(__DIR__, 6) . '/translations/cregserv.' . $locale . '.yaml';
        $catalog = $this->requireStringKeyedArray(Yaml::parseFile($path), $locale . ' catalog must parse as a mapping');

        return $this->requireStringKeyedArray($catalog['help'] ?? null, $locale . ' must define the help subtree');
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

    /**
     * @param array<string|int, mixed> $values
     */
    private function assertHelpColorsAllowed(array $values, string $locale, string $path = 'help'): void
    {
        foreach ($values as $key => $value) {
            $currentPath = $path . '.' . $key;
            if (is_array($value)) {
                $this->assertHelpColorsAllowed($value, $locale, $currentPath);
                continue;
            }
            if (!is_string($value)) {
                continue;
            }

            preg_match_all('/\x03([0-9]{1,2})/', $value, $matches);
            foreach ($matches[1] as $color) {
                $color = str_pad($color, 2, '0', STR_PAD_LEFT);
                self::assertContains($color, self::ALLOWED_HELP_COLORS, sprintf('%s contains forbidden structural color %s', $locale . ':' . $currentPath, $color));
            }
        }
    }
}

final class CRegServHelpFormatterContext implements HelpFormatterContextInterface
{
    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $replies = [];

    /** @var list<string> */
    public array $rawReplies = [];

    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $translations = [];

    /**
     * @param list<HelpableCommandInterface>                                                      $commands
     * @param list<HelpableCommandInterface>                                                      $ircopCommands
     * @param list<string>                                                                        $visibleCommands
     * @param list<array{group_key: string, commands: list<string>, admin: bool, subgroup: bool}> $helpGroups
     */
    public function __construct(
        private readonly array $commands = [],
        private readonly array $ircopCommands = [],
        private readonly array $visibleCommands = [],
        private readonly bool $ircopAccess = false,
        private readonly array $helpGroups = [],
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

        return 'translated:' . $key;
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
        if ([] !== $this->helpGroups) {
            return $this->helpGroups;
        }

        return [
            ['group_key' => 'help.group.requests', 'commands' => ['VISIBLE'], 'admin' => false, 'subgroup' => false],
            ['group_key' => 'help.ircop_group.approval', 'commands' => ['APROBAR', 'SAREGISTER'], 'admin' => true, 'subgroup' => true],
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

final readonly class CRegServHelpableCommand implements HelpableCommandInterface
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
