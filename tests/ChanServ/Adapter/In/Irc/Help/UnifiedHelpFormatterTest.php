<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Help;

use App\ChanServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\ChanServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\ChanServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use LogicException;
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
        $context = new ChanServHelpFormatterContext(
            commands: [
                new ChanServHelpableCommand('HIDDEN', 2),
                new ChanServHelpableCommand('VISIBLE', 1),
                new ChanServHelpableCommand('HELP', 0),
            ],
            ircopCommands: [
                new ChanServHelpableCommand('LATE', 9),
                new ChanServHelpableCommand('EARLY', 1),
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
        self::assertContains('help.ircop_header', array_column($context->translations, 'key'));
        self::assertContains([
            'key' => 'help.header',
            'params' => ['title' => 'translated:help.header_title', 'separator' => str_repeat('─', 40 - 3 - mb_strlen('translated:help.header_title'))],
        ], $context->translations);
        self::assertContains('help.group_header', array_column($context->translations, 'key'));
        self::assertContains('help.subgroup_header', array_column($context->translations, 'key'));
    }

    #[Test]
    public function rendersEachChanServIrcopSubgroupAndItsCommands(): void
    {
        $groups = [
            ['group_key' => 'help.ircop_group.channel_operations', 'commands' => ['CLEARUSERS', 'CLEARACCESS', 'IRCOPONLY'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.channel_management', 'commands' => ['DROP', 'NOEXPIRE', 'RESTORE'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.restrictions', 'commands' => ['SUSPEND', 'UNSUSPEND', 'FORBID', 'UNFORBID'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.lookup', 'commands' => ['HISTORY', 'LIST'], 'admin' => true, 'subgroup' => true],
        ];
        $commandNames = array_merge(...array_column($groups, 'commands'));
        $commands = [];
        foreach ($commandNames as $order => $name) {
            $commands[] = new ChanServHelpableCommand($name, $order);
        }
        $context = new ChanServHelpFormatterContext(
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
        self::assertCount(4, array_filter($translatedKeys, static fn (string $key): bool => 'help.subgroup_header' === $key));

        $commandLines = array_values(array_filter(
            $context->translations,
            static fn (array $reply): bool => 'help.command_line' === $reply['key'],
        ));
        self::assertSame(
            array_map(static fn (string $name): string => str_pad($name, 12), $commandNames),
            array_column(array_column($commandLines, 'params'), 'command'),
        );
    }

    #[Test]
    public function rendersUngroupedCommandAndGeneralFooterWithoutAdminCommands(): void
    {
        $command = new ChanServHelpableCommand('FUTURE', 1);
        $context = new ChanServHelpFormatterContext(
            commands: [$command],
            visibleCommands: ['FUTURE'],
        );

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context);

        $keys = array_column($context->translations, 'key');
        self::assertContains('help.general_footer', $keys);
        self::assertNotContains('help.ircop_header', $keys);
        $commandLines = array_values(array_filter(
            $context->translations,
            static fn (array $reply): bool => 'help.command_line' === $reply['key'],
        ));
        self::assertSame(['FUTURE      '], array_column(array_column($commandLines, 'params'), 'command'));
        self::assertSame(['translated:future.short'], array_column(array_column($commandLines, 'params'), 'description'));
    }

    #[Test]
    public function rendersCommandHelpWithParametersAndOptions(): void
    {
        $context = new ChanServHelpFormatterContext();
        $command = new ChanServHelpableCommand('SET', 1, [[
            'name' => 'EMAIL',
            'desc_key' => 'set.email.short',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
        ]], ['service' => 'ChanServ']);

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

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'ChanServ']], $context->translations);
        self::assertContains([
            'key' => 'help.subcommand_line',
            'params' => ['command' => 'EMAIL     ', 'description' => 'translated:set.email.short'],
        ], $context->translations);
        self::assertContains([
            'key' => 'help.syntax_label',
            'params' => ['syntax' => 'translated:set.syntax'],
        ], $context->translations);
    }

    #[Test]
    public function rendersSubcommandHelpWithOptionalDetails(): void
    {
        $context = new ChanServHelpFormatterContext();

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
        self::assertSame('HELP SET EMAIL', $context->translations[0]['params']['title']);
    }

    #[Test]
    public function definesHeaderTemplateAndPlaceholdersInEveryLocale(): void
    {
        $context = new ChanServHelpFormatterContext();
        $header = new UnifiedHelpFormatter()->renderHeader($context, 'HELP SET EMAIL');

        self::assertSame('translated:help.header', $header);
        self::assertSame([], $context->replies);
        self::assertSame([], $context->rawReplies);

        self::assertSame([
            'key' => 'help.header',
            'params' => ['title' => 'HELP SET EMAIL', 'separator' => str_repeat('─', 23)],
        ], $context->translations[0]);

        $expectedTemplate = "\x02\x0307● %title%\x03\x0F \x0314%separator%\x03";
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
        }
    }

    #[Test]
    public function definesChanServHelpGroupsAndLookupDescriptionsInEveryLocale(): void
    {
        $expectedPublicGroups = ['registration_info', 'access_levels', 'ranks_entry'];
        $expectedIrcopGroups = ['channel_operations', 'channel_management', 'restrictions', 'lookup'];

        foreach (self::LOCALES as $locale) {
            $catalog = $this->loadCatalog($locale);
            $help = $this->requireStringKeyedArray($catalog['help'] ?? null, $locale . ' must define the help subtree');
            $publicGroups = $this->requireStringKeyedArray($help['group'] ?? null, $locale . ' must define public HELP groups');
            $ircopGroups = $this->requireStringKeyedArray($help['ircop_group'] ?? null, $locale . ' must define IRCop HELP groups');
            self::assertSame($expectedPublicGroups, array_keys($publicGroups), $locale);
            self::assertSame($expectedIrcopGroups, array_keys($ircopGroups), $locale);
            foreach ($ircopGroups as $label) {
                self::assertIsString($label, $locale);
                self::assertNotSame('', trim($label), $locale);
            }

            $nickServCatalog = Yaml::parseFile(dirname(__DIR__, 6) . '/translations/nickserv.' . $locale . '.yaml');
            $nickServ = $this->requireStringKeyedArray($nickServCatalog, $locale . ' NickServ catalog must parse as a mapping');
            $nickServHelp = $this->requireStringKeyedArray($nickServ['help'] ?? null, $locale . ' NickServ must define the help subtree');
            $nickServIrcopGroups = $this->requireStringKeyedArray($nickServHelp['ircop_group'] ?? null, $locale . ' NickServ must define IRCop HELP groups');
            self::assertSame($nickServIrcopGroups['lookup'] ?? null, $ircopGroups['lookup'] ?? null, $locale);

            $list = $this->requireStringKeyedArray($catalog['list'] ?? null, $locale . ' must define the IRCop LIST command');
            self::assertIsString($list['short'] ?? null, $locale);
            self::assertDoesNotMatchRegularExpression('/\\([^)]*IRCops?[^)]*\\)\\.?$/iu', $list['short'], $locale);
        }
    }

    #[Test]
    public function rendersCanonicalHelpColorsAndLeavesDescriptionsUncolored(): void
    {
        $help = $this->loadHelpCatalog('en');
        $context = new ChanServHelpFormatterContext(
            commands: [new ChanServHelpableCommand('VISIBLE', 1)],
            visibleCommands: ['VISIBLE'],
        );

        $lines = new UnifiedHelpFormatter()->renderGeneralHelp($context);

        $title = 'translated:help.header_title';
        self::assertSame(
            "\x02\x0307● {$title}\x03\x0F \x0314" . str_repeat('─', max(0, 40 - 3 - mb_strlen($title))) . "\x03",
            $this->renderReply($context->translations, $help, 'help.header'),
        );
        self::assertSame("\x02\x0307Available commands:\x03\x0F", $this->renderReply($context->translations, $help, 'help.general_header'));
        self::assertSame("\x02\x0307◆ translated:help.group.public\x03\x0F", $this->renderReply($context->translations, $help, 'help.group_header'));

        $renderedCommand = $this->renderReply($context->translations, $help, 'help.command_line');
        self::assertSame("  \x0310›\x03 \x0303VISIBLE     \x03translated:visible.short", $renderedCommand);
        $descriptionOffset = strpos($renderedCommand, 'translated:visible.short');
        self::assertNotFalse($descriptionOffset);
        self::assertSame('translated:visible.short', substr($renderedCommand, $descriptionOffset));

        $setContext = new ChanServHelpFormatterContext();
        $lines = new UnifiedHelpFormatter()->renderCommandHelp($setContext, new ChanServHelpableCommand('SET', 1, [[
            'name' => 'EMAIL',
            'desc_key' => 'set.email.short',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
        ]]));
        self::assertSame("\x02\x0307◆ Options:\x03\x0F", $this->renderReply($setContext->translations, $help, 'help.options_header'));
        self::assertSame("  \x0310›\x03 \x0303EMAIL     \x03translated:set.email.short", $this->renderReply($setContext->translations, $help, 'help.subcommand_line'));

        $introExpiration = $help['intro_expiration'] ?? null;
        self::assertIsString($introExpiration);
        self::assertSame("\x0307⚠\x03 NOTE: Channels unused for more than 30 days are automatically removed.", $this->renderTemplate($introExpiration, ['days' => '30']));

        $unknownCommand = $help['unknown_command'] ?? null;
        self::assertIsString($unknownCommand);
        self::assertSame("\x0307✗\x03 Unknown command \x02FUTURE\x02. Use \x0303/msg ChanServ HELP\x03.", $this->renderTemplate($unknownCommand, ['command' => 'FUTURE', 'bot' => 'ChanServ']));
        self::assertSame("\x0314─────────────────────────────\x03", $help['footer']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCatalog(string $locale): array
    {
        $path = dirname(__DIR__, 6) . '/translations/chanserv.' . $locale . '.yaml';

        return $this->requireStringKeyedArray(Yaml::parseFile($path), $locale . ' catalog must parse as a mapping');
    }

    /**
     * @return array<string, mixed>
     */
    private function loadHelpCatalog(string $locale): array
    {
        $catalog = $this->loadCatalog($locale);

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

    /**
     * @param list<array{key: string, params: array<string, mixed>}> $replies
     * @param array<string, mixed>                                   $help
     */
    private function renderReply(array $replies, array $help, string $key): string
    {
        foreach ($replies as $reply) {
            if ($key !== $reply['key']) {
                continue;
            }

            $template = $help[substr($key, 5)] ?? null;
            self::assertIsString($template, $key . ' translation must exist');

            return $this->renderTemplate($template, $reply['params']);
        }

        throw new LogicException(sprintf('Formatter did not emit %s.', $key));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function renderTemplate(string $template, array $params): string
    {
        $replacements = [];
        foreach ($params as $key => $value) {
            self::assertIsScalar($value);
            $replacements['%' . $key . '%'] = (string) $value;
        }

        return strtr($template, $replacements);
    }
}

final class ChanServHelpFormatterContext implements HelpFormatterContextInterface
{
    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $replies = [];

    /** @var list<string> */
    public array $rawReplies = [];

    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $translations = [];

    /**
     * @param list<HelpableCommandInterface> $commands
     * @param list<HelpableCommandInterface> $ircopCommands
     * @param list<string>                   $visibleCommands
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

final readonly class ChanServHelpableCommand implements HelpableCommandInterface
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
