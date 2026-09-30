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

use function in_array;

#[CoversClass(UnifiedHelpFormatter::class)]
final class UnifiedHelpFormatterTest extends TestCase
{
    #[Test]
    public function nickServHelpCatalogsUseOnlyTheApprovedResetPalette(): void
    {
        $root = dirname(__DIR__, 6);
        $locales = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];
        $expectedColors = [
            'header' => ['06', '14'],
            'navigation_marker' => ['10'],
            'info_marker' => ['10'],
            'error_marker' => ['04'],
            'warning_marker' => ['07'],
            'intro_expiration' => ['07'],
            'general_header' => ['06'],
            'command_line' => ['03'],
            'subcommand_line' => ['03'],
            'options_header' => ['06'],
            'general_footer' => ['03'],
            'set_sub_footer' => ['03'],
            'syntax_label' => ['03'],
            'footer' => ['14'],
            'group_header' => ['06'],
            'subgroup_header' => ['04'],
            'ircop_header' => ['04'],
            'set_timezone.index_label' => ['03'],
            'set_timezone.region_header' => ['06'],
            'set_timezone.region_unknown' => ['03'],
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
                    substr_count($value, "\x03\x0F"),
                    $locale . ': each colored fragment must reset color and formatting',
                );
            }

            $helpText = serialize($help);
            preg_match_all('/\x03(\d{2})/', $helpText, $allColors);
            self::assertNotEmpty($allColors[1], $locale);
            self::assertSame(
                count($allColors[1]),
                substr_count($helpText, "\x03\x0F"),
                $locale . ': every HELP color must reset',
            );
            self::assertSame([], array_diff($allColors[1], ['03', '04', '06', '07', '10', '14']), $locale);
        }

        $commandSource = file_get_contents($root . '/src/NickServ/Adapter/In/Irc/Command/HelpCommand.php');
        self::assertIsString($commandSource);
        foreach (['IrcHelpStyle', "\x03", '›', 'ℹ', '⚠', '✗', '◆'] as $presentationToken) {
            self::assertStringNotContainsString($presentationToken, $commandSource);
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

        new UnifiedHelpFormatter()->showGeneralHelp($context);

        $renderedCommands = [];
        foreach ($context->replies as $reply) {
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
            $context->replies,
            static fn (array $reply): bool => 'help.command_line' === $reply['key'],
        ));
        self::assertSame(
            ['translated:help.navigation_marker', 'translated:help.navigation_marker', 'translated:help.navigation_marker'],
            array_column(array_column($commandLines, 'params'), 'marker'),
        );
        self::assertContains([
            'key' => 'help.header',
            'params' => ['title' => 'translated:help.header_title'],
        ], $context->replies);
        self::assertContains('translated:help.general_header', $context->rawReplies);
        self::assertContains('translated:help.ircop_header', $context->rawReplies);
        self::assertContains('translated:help.group_header', $context->rawReplies);
        self::assertContains('translated:help.subgroup_header', $context->rawReplies);
        self::assertContains([
            'key' => 'help.general_footer',
            'params' => [
                'marker' => 'translated:help.info_marker',
                'syntax' => 'translated:help.general_syntax',
            ],
        ], $context->replies);
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

        new UnifiedHelpFormatter()->showGeneralHelp($context);

        $keys = array_column($context->replies, 'key');
        self::assertContains('help.general_footer', $keys);
        self::assertContains([
                'key' => 'help.general_footer',
            'params' => [
                'marker' => 'translated:help.info_marker',
                'syntax' => 'translated:help.general_syntax',
            ],
        ], $context->replies);
        self::assertNotContains('help.ircop_header', $keys);
        $commandLines = array_values(array_filter(
            $context->replies,
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

        new UnifiedHelpFormatter()->showCommandHelp($context, $command);

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'NickServ']], $context->replies);
        self::assertContains([
            'key' => 'help.subcommand_line',
            'params' => [
                'marker' => 'translated:help.navigation_marker',
                'command' => 'EMAIL     ',
                'description' => 'translated:set.email.short',
            ],
        ], $context->replies);
        self::assertContains([
            'key' => 'help.syntax_label',
            'params' => ['syntax' => 'translated:set.syntax'],
        ], $context->replies);
        self::assertContains([
            'key' => 'help.set_sub_footer',
            'params' => [
                'marker' => 'translated:help.info_marker',
                'syntax' => 'translated:help.set_sub_syntax',
            ],
        ], $context->replies);
        self::assertContains([
            'key' => 'help.set_sub_syntax',
            'params' => ['command' => 'SET'],
        ], $context->translations);
        self::assertContains('translated:help.options_header', $context->rawReplies);
    }

    #[Test]
    public function rendersSubcommandHelpWithOptionalDetails(): void
    {
        $context = new NickServHelpFormatterContext();

        new UnifiedHelpFormatter()->showSubCommandHelp($context, 'SET', [
            'name' => 'EMAIL',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
            'options_key' => 'set.email.options',
        ]);

        self::assertSame([
            'help.header',
            'set.email.help',
            'set.email.options',
            'help.syntax_label',
        ], array_column($context->replies, 'key'));
        self::assertContains([
            'key' => 'help.header',
            'params' => ['title' => 'HELP SET EMAIL'],
        ], $context->replies);
        self::assertSame('translated:help.footer', $context->rawReplies[array_key_last($context->rawReplies)]);
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
