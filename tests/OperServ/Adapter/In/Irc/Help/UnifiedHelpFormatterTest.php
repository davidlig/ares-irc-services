<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Help;

use App\OperServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\OperServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\OperServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function dirname;
use function in_array;
use function is_array;
use function is_string;
use function strlen;

use const PREG_OFFSET_CAPTURE;

#[CoversClass(UnifiedHelpFormatter::class)]
final class UnifiedHelpFormatterTest extends TestCase
{
    private const array LOCALES = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];

    private const array ALLOWED_COLORS = ['03', '04', '06', '07', '10', '14'];

    #[Test]
    public function rendersFilteredGroupedGeneralHelp(): void
    {
        $context = new OperServHelpFormatterContext(
            commands: [
                new OperServHelpableCommand('HIDDEN', 2),
                new OperServHelpableCommand('VISIBLE', 1),
                new OperServHelpableCommand('HELP', 0),
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
        self::assertSame('translated:help.header_title', $context->translationRequests[1]['params']['title']);
        self::assertContains('help.group_header', array_column($context->translationRequests, 'key'));
    }

    #[Test]
    public function mergesIrcopCommandsAndRendersAdminSubgroupAndUngroupedCommand(): void
    {
        $visible = new OperServHelpableCommand('VISIBLE', 1);
        $ircop = new OperServHelpableCommand('IRCOP', 2);
        $ungrouped = new OperServHelpableCommand('FUTURE', 3);
        $context = new OperServHelpFormatterContext(
            commands: [$visible, $ungrouped],
            ircopCommands: [$ircop],
            visibleCommands: ['VISIBLE', 'FUTURE'],
            ircopAccess: true,
            helpGroups: [
                ['group_key' => 'help.group.empty', 'commands' => ['MISSING'], 'admin' => false, 'subgroup' => false],
                ['group_key' => 'help.group.public', 'commands' => ['VISIBLE'], 'admin' => false, 'subgroup' => false],
                ['group_key' => 'help.ircop_group.operations', 'commands' => ['IRCOP'], 'admin' => true, 'subgroup' => true],
            ],
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

        $keys = array_column($context->translationRequests, 'key');
        self::assertContains('help.general_footer', $keys);
        self::assertContains('help.ircop_header', $keys);
        self::assertContains('help.subgroup_header', $keys);
        $commandLines = array_values(array_filter(
            $context->translationRequests,
            static fn (array $reply): bool => 'help.command_line' === $reply['key'],
        ));
        self::assertSame(['VISIBLE     ', 'IRCOP       ', 'FUTURE      '], array_column(array_column($commandLines, 'params'), 'command'));
    }

    #[Test]
    public function sortsAndDeduplicatesGroupedCommandsAndPreservesUngroupedFallback(): void
    {
        $context = new OperServHelpFormatterContext(
            commands: [
                new OperServHelpableCommand('LATE', 2),
                new OperServHelpableCommand('EARLY', 1),
                new OperServHelpableCommand('FUTURE', 3),
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
        $context = new OperServHelpFormatterContext();
        $command = new OperServHelpableCommand('SET', 1, [[
            'name' => 'EMAIL',
            'desc_key' => 'set.email.short',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
        ]], ['service' => 'OperServ']);

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

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'OperServ']], $context->translationRequests);
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
        $context = new OperServHelpFormatterContext();

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
        self::assertSame('help.header', $context->translationRequests[0]['key']);
        self::assertSame('HELP SET EMAIL', $context->translationRequests[0]['params']['title']);
    }

    #[Test]
    public function preservesMultibyteHeaderWidthAndDoesNotPadOverlongTitles(): void
    {
        $context = new OperServHelpFormatterContext();
        $formatter = new UnifiedHelpFormatter();

        self::assertSame('translated:help.header', $formatter->renderHeader($context, 'Áé🙂'));
        self::assertSame('translated:help.header', $formatter->renderHeader($context, str_repeat('X', 50)));
        self::assertSame([
            ['key' => 'help.header', 'params' => ['title' => 'Áé🙂', 'separator' => str_repeat('─', 34)]],
            ['key' => 'help.header', 'params' => ['title' => str_repeat('X', 50), 'separator' => '']],
        ], $context->translationRequests);
    }

    #[Test]
    public function operServCatalogsUseOnlyCanonicalHelpColorsAndKeepPlaceholderContracts(): void
    {
        $root = dirname(__DIR__, 6);
        $colorRoles = [
            'help.header' => ['06', '14'],
            'help.general_header' => ['06'],
            'help.options_header' => ['06'],
            'help.command_line' => ['10', '03'],
            'help.subcommand_line' => ['10', '03'],
            'help.general_footer' => ['10', '03'],
            'help.set_sub_footer' => ['10', '03'],
            'help.syntax_label' => ['03'],
            'help.group_header' => ['06'],
            'help.subgroup_header' => ['04'],
            'help.unknown_command' => ['04', '03'],
            'help.ircop_header' => ['04'],
            'help.intro_expiration' => ['07'],
            'help.footer' => ['14'],
        ];
        $placeholderContracts = [
            'help.header' => ['%title%', '%separator%'],
            'help.command_line' => ['%command%', '%description%'],
            'help.subcommand_line' => ['%command%', '%description%'],
            'help.general_footer' => ['%bot%'],
            'help.set_sub_footer' => ['%bot%', '%command%'],
            'help.syntax_label' => ['%syntax%'],
            'help.group_header' => ['%group%'],
            'help.subgroup_header' => ['%group%'],
            'help.unknown_command' => ['%command%', '%bot%'],
        ];

        foreach (self::LOCALES as $locale) {
            $catalog = Yaml::parseFile($root . '/translations/operserv.' . $locale . '.yaml');
            self::assertIsArray($catalog, $locale);
            self::assertArrayHasKey('help', $catalog, $locale);
            self::assertIsArray($catalog['help'], $locale);

            $entries = [];
            $flatten = static function (array $values, string $prefix) use (&$flatten, &$entries): void {
                foreach ($values as $key => $value) {
                    $path = $prefix . '.' . (string) $key;
                    if (is_array($value)) {
                        $flatten($value, $path);
                    } elseif (is_string($value)) {
                        $entries[$path] = $value;
                    }
                }
            };
            $flatten($catalog['help'], 'help');

            foreach ($entries as $key => $value) {
                preg_match_all('/\x03([0-9]{1,2})/', $value, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[1] as $index => [$color, $offset]) {
                    self::assertSame(2, strlen($color), $locale . ': ' . $key . ' uses a two-digit mIRC color');
                    self::assertContains($color, self::ALLOWED_COLORS, $locale . ': ' . $key . ' uses canonical colors only');

                    $fragmentStart = $offset + 2;
                    $nextColorOffset = $matches[0][$index + 1][1] ?? strlen($value);
                    $fragment = substr($value, $fragmentStart, $nextColorOffset - $fragmentStart);
                    self::assertTrue(
                        str_contains($fragment, "\x0F") || 1 === preg_match('/\x03(?![0-9]{2})/', $fragment),
                        $locale . ': ' . $key . ' resets each colored fragment',
                    );
                }
            }

            foreach ($colorRoles as $key => $colors) {
                self::assertArrayHasKey($key, $entries, $locale);
                preg_match_all('/\x03([0-9]{2})/', $entries[$key], $matches);
                foreach ($colors as $color) {
                    self::assertContains($color, $matches[1], $locale . ': ' . $key . ' uses its semantic palette role');
                }
            }

            foreach ($placeholderContracts as $key => $placeholders) {
                self::assertArrayHasKey($key, $entries, $locale);
                foreach ($placeholders as $placeholder) {
                    self::assertStringContainsString($placeholder, $entries[$key], $locale . ': ' . $key . ' retains ' . $placeholder);
                }
            }

            self::assertStringContainsString(
                "\x0310›\x03\x0F \x02\x0303%command%\x03\x0F%description%",
                $entries['help.command_line'],
                $locale . ': command descriptions return to the default foreground',
            );
            self::assertMatchesRegularExpression(
                '/\x0304✗ [^\x03]+\x03\x0F \x02\x0303%command%\x03\x0F/',
                $entries['help.unknown_command'],
                $locale . ': error label and command use their semantic colors and reset',
            );
        }
    }
}

final class OperServHelpFormatterContext implements HelpFormatterContextInterface
{
    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $translationRequests = [];

    /**
     * @param list<HelpableCommandInterface>                                                           $commands
     * @param list<HelpableCommandInterface>                                                           $ircopCommands
     * @param list<string>                                                                             $visibleCommands
     * @param list<array{group_key: string, commands: list<string>, admin: bool, subgroup: bool}>|null $helpGroups
     */
    public function __construct(
        private readonly array $commands = [],
        private readonly array $ircopCommands = [],
        private readonly array $visibleCommands = [],
        private readonly bool $ircopAccess = false,
        private readonly ?array $helpGroups = null,
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
        return $this->shouldShowCommandInGeneralHelp($command);
    }

    public function getHelpGroups(): array
    {
        return $this->helpGroups ?? [
            ['group_key' => 'help.group.operations', 'commands' => ['VISIBLE', 'HIDDEN'], 'admin' => false, 'subgroup' => false],
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

final readonly class OperServHelpableCommand implements HelpableCommandInterface
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
