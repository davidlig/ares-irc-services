<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Help;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\In\Event\CoreSendNoticeAdapter;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\ProtocolHandlerInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use App\OperServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\OperServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\OperServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\Tests\Shared\Translations\TranslationCatalog;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function dirname;
use function in_array;
use function is_array;
use function is_string;
use function str_contains;
use function str_ends_with;
use function strlen;

use const PREG_OFFSET_CAPTURE;

#[CoversClass(UnifiedHelpFormatter::class)]
#[UsesClass(CoreSendNoticeAdapter::class)]
#[UsesClass(ActiveConnectionHolder::class)]
#[UsesClass(NetworkBurstCompleteEvent::class)]
#[UsesClass(IRCMessage::class)]
final class UnifiedHelpFormatterTest extends TestCase
{
    #[Test]
    public function keepsSpanishCommandExamplesIndependentOfMessageTransport(): void
    {
        $catalog = TranslationCatalog::parseFile(dirname(__DIR__, 6) . '/translations/operserv.es.yaml');
        $examples = [];
        $collectExamples = static function (mixed $value) use (&$collectExamples, &$examples): void {
            if (!is_array($value)) {
                return;
            }

            foreach ($value as $key => $item) {
                if ('example' === $key && is_string($item)) {
                    $examples[] = $item;
                    continue;
                }
                if ('examples' === $key && is_array($item)) {
                    foreach ($item as $example) {
                        if (is_string($example)) {
                            $examples[] = $example;
                        }
                    }
                    continue;
                }
                $collectExamples($item);
            }
        };
        $collectExamples($catalog);
        self::assertNotEmpty($examples);

        foreach ($examples as $example) {
            self::assertStringNotContainsString('/msg ', $example);
        }
    }

    private const array LOCALES = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];

    private const array ALLOWED_COLORS = ['03', '07', '10', '14'];

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
            ' ',
            'translated:help.intro',
            ' ',
            ' ',
            'translated:help.general_header',
            ' ',
            'translated:help.group_header',
            'translated:help.command_line',
            ' ',
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
            ' ',
            'translated:help.intro',
            ' ',
            ' ',
            'translated:help.general_header',
            ' ',
            'translated:help.group_header',
            'translated:help.command_line',
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
        self::assertSame([' ', ' ', 'translated:help.general_footer'], array_slice($lines, -3));
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
            'example_key' => 'set.email.example',
        ]], ['service' => 'OperServ']);

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

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'OperServ']], $context->translationRequests);
        self::assertContains(['key' => 'help.examples_header', 'params' => []], $context->translationRequests);
        self::assertContains(['key' => 'set.example', 'params' => []], $context->translationRequests);
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
    public function addsConfiguredUncoloredMessagePrefixToCommandOnlyExamples(): void
    {
        $context = new OperServHelpFormatterContext();
        $context->botName = 'ConfiguredBot';
        $context->exampleTranslations['test.examples.0'] = "%command_prefix%\x02\x0303HELP\x03\x0F";
        $context->exampleTranslations['test.example'] = "%command_prefix%\x02\x0303IRCOP LIST\x03\x0F";

        $lines = new UnifiedHelpFormatter()->renderCommandHelp($context, new OperServHelpableCommand('TEST', 1));

        self::assertContains('  /msg ConfiguredBot ' . "\x02\x0303IRCOP LIST\x03\x0F", $lines);
        $firstExample = array_search('  /msg ConfiguredBot ' . "\x02\x0303IRCOP LIST\x03\x0F", $lines, true);
        self::assertIsInt($firstExample);
        self::assertSame("  /msg ConfiguredBot \x02\x0303HELP\x03\x0F", $lines[$firstExample + 1]);
    }

    #[Test]
    public function preservesSubcommandParagraphRowsWithoutChangingCommandHelp(): void
    {
        $context = new OperServHelpFormatterContext();
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
        self::assertSame($body, $formatter->renderCommandHelp($context, new OperServHelpableCommand('TEST', 1))[2]);
    }

    #[Test]
    public function localizedRoleSubcommandParagraphRowsSurviveIrcDelivery(): void
    {
        $holder = new ActiveConnectionHolder();
        $sentRows = [];
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isConnected')->willReturn(true);
        $connection->expects(self::exactly(56))->method('writeLine')->willReturnCallback(static function (array $batch) use (&$sentRows): void {
            $sentRows = $batch;
        });
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('formatMessage')->willReturnCallback(static fn (IRCMessage $message): string => 'NOTICE 001USER :' . ($message->trailing ?? ''));
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getHandler')->willReturn($handler);
        $holder->onBurstComplete(new NetworkBurstCompleteEvent($connection, '001'));
        $holder->setProtocolModule($module);
        $adapter = new CoreSendNoticeAdapter($holder);

        foreach (self::LOCALES as $locale) {
            $catalog = TranslationCatalog::parseFile(dirname(__DIR__, 6) . '/translations/operserv.' . $locale . '.yaml');
            foreach (['perms', 'modes', 'vhost', 'operclass'] as $action) {
                $role = $catalog['role'] ?? null;
                self::assertIsArray($role, $locale);
                $subcommand = $role[$action] ?? null;
                self::assertIsArray($subcommand, $locale . ': ' . $action);
                $body = $subcommand['help'] ?? null;
                self::assertIsString($body, $locale . ': ' . $action);
                $context = new OperServHelpFormatterContext();
                $context->exampleTranslations['test.help'] = $body;
                $lines = new UnifiedHelpFormatter()->renderSubCommandHelp($context, 'ROLE', [
                    'name' => strtoupper($action),
                    'help_key' => 'test.help',
                    'syntax_key' => 'test.syntax',
                    'example_key' => 'test.example',
                ]);

                $adapter->sendNotice('001OS', '001USER', implode("\n", $lines));

                $expectedRows = array_map(
                    static fn (string $line): string => 'NOTICE 001USER :' . ('' === $line ? ' ' : $line),
                    explode("\n", implode("\n", $lines)),
                );
                self::assertSame($expectedRows, $sentRows, $locale . ': ' . $action);
                self::assertSame(
                    implode("\n", array_map(static fn (string $line): string => '' === $line ? ' ' : $line, explode("\n", $body))),
                    $lines[2],
                    $locale . ': ' . $action,
                );
            }
        }
    }

    #[Test]
    public function rendersSubcommandHelpWithOptionalDetails(): void
    {
        $context = new OperServHelpFormatterContext();

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
        self::assertSame('help.header', $context->translationRequests[0]['key']);
        self::assertSame('HELP SET EMAIL', $context->translationRequests[0]['params']['title']);
    }

    #[Test]
    public function omitsExamplesWhenTheCurrentLocaleHasNoExampleTranslations(): void
    {
        $context = new OperServHelpFormatterContext(missingExampleTranslations: true);
        $formatter = new UnifiedHelpFormatter();
        $command = new OperServHelpableCommand('KILL', 1, [[
            'name' => 'FORCE',
            'desc_key' => 'kill.force.short',
            'help_key' => 'kill.force.help',
            'syntax_key' => 'kill.force.syntax',
            'example_key' => 'kill.force.example',
        ]]);

        $commandLines = $formatter->renderCommandHelp($context, $command);
        $subcommandLines = $formatter->renderSubCommandHelp($context, 'KILL', [
            'name' => 'FORCE',
            'help_key' => 'kill.force.help',
            'syntax_key' => 'kill.force.syntax',
            'example_key' => 'kill.force.example',
        ]);

        self::assertNotContains('help.examples_header', $commandLines);
        self::assertNotContains('kill.example', $commandLines);
        self::assertNotContains('help.examples_header', $subcommandLines);
        self::assertNotContains('kill.force.example', $subcommandLines);
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
            'help.header' => ['07', '14'],
            'help.general_header' => [],
            'help.options_header' => [],
            'help.command_line' => ['10', '03'],
            'help.subcommand_line' => ['10', '03'],
            'help.general_footer' => ['10', '03'],
            'help.set_sub_footer' => ['10', '03'],
            'help.syntax_label' => ['03'],
            'help.group_header' => ['10'],
            'help.subgroup_header' => ['10'],
            'help.ircop_header' => [],
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
        ];

        foreach (self::LOCALES as $locale) {
            $catalog = TranslationCatalog::parseFile($root . '/translations/operserv.' . $locale . '.yaml');
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

            self::assertSame("\x02\x0307🤖 %title%\x03\x0F \x0314%separator%\x03\x0F", $entries['help.header'], $locale);
            foreach (['general_header', 'options_header', 'ircop_header'] as $section) {
                self::assertStringStartsWith("\x02 ■ ", $entries['help.' . $section], $locale);
                self::assertStringEndsWith("\x02", $entries['help.' . $section], $locale);
                self::assertStringNotContainsString("\x03", $entries['help.' . $section], $locale);
            }
            foreach (['group_header', 'subgroup_header'] as $group) {
                self::assertSame("\x0310  ◆ %group%\x03\x0F", $entries['help.' . $group], $locale);
            }
            foreach (['command_line', 'subcommand_line'] as $row) {
                self::assertSame("    \x0310›\x03\x0F \x02\x0303%command%\x03\x0F%description%", $entries['help.' . $row], $locale);
            }
            foreach (['general_footer', 'set_sub_footer'] as $hint) {
                self::assertStringStartsWith("\x0310ℹ\x03\x0F ", $entries['help.' . $hint], $locale);
                self::assertMatchesRegularExpression('/[^\x02\x03]*\/msg %bot% \x02\x0303HELP [^\x03]+\x03\x0F/u', $entries['help.' . $hint], $locale);
            }

            foreach ($placeholderContracts as $key => $placeholders) {
                self::assertArrayHasKey($key, $entries, $locale);
                foreach ($placeholders as $placeholder) {
                    self::assertStringContainsString($placeholder, $entries[$key], $locale . ': ' . $key . ' retains ' . $placeholder);
                }
            }

            self::assertStringContainsString(
                "    \x0310›\x03\x0F \x02\x0303%command%\x03\x0F%description%",
                $entries['help.command_line'],
                $locale . ': command descriptions return to the default foreground',
            );
            $commonCatalog = TranslationCatalog::parseFile($root . '/translations/common.' . $locale . '.yaml');
            $unknownCommand = $commonCatalog['unknown_command'] ?? null;
            self::assertIsString($unknownCommand, $locale . ': common catalog defines unknown_command');
            self::assertStringStartsWith("\x0304✗\x03\x0F ", $unknownCommand, $locale);
            self::assertStringContainsString("\x0303%command%\x03\x0F", $unknownCommand, $locale);
            self::assertStringContainsString("/msg %bot% \x0303HELP", $unknownCommand, $locale);
            self::assertStringEndsWith("\x0303HELP\x03\x0F", $unknownCommand, $locale);
            self::assertDoesNotMatchRegularExpression('/\x03\d{1,2}\/msg/', $unknownCommand, $locale);
        }
    }
}

final class OperServHelpFormatterContext implements HelpFormatterContextInterface
{
    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $translationRequests = [];

    /** @var array<string, string> */
    public array $exampleTranslations = [];

    public string $botName = 'OperServ';

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
        private readonly bool $missingExampleTranslations = false,
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
