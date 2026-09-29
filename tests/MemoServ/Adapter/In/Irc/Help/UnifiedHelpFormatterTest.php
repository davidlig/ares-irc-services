<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc\Help;

use App\MemoServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\MemoServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\MemoServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

use function in_array;
use function is_array;

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

        new UnifiedHelpFormatter()->showGeneralHelp($context);

        $renderedCommands = [];
        foreach ($context->replies as $reply) {
            if ('help.command_line' !== $reply['key']) {
                continue;
            }

            self::assertIsString($reply['params']['command']);
            $renderedCommands[] = $reply['params']['command'];
        }
        self::assertSame(['VISIBLE     '], $renderedCommands);
        self::assertNotContains('help.ircop_header', array_column($context->replies, 'key'));
        self::assertSame('help.header', $context->replies[0]['key']);
        self::assertSame('MemoServ', $context->replies[0]['params']['title']);
        self::assertContains('help.group_header', array_column($context->replies, 'key'));
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

        new UnifiedHelpFormatter()->showGeneralHelp($context);

        $keys = array_column($context->replies, 'key');
        self::assertContains('help.general_footer', $keys);
        self::assertContains('help.ircop_header', $keys);
        self::assertContains('help.subgroup_header', $keys);
        $commandLines = array_values(array_filter(
            $context->replies,
            static fn (array $reply): bool => 'help.command_line' === $reply['key'],
        ));
        self::assertSame(['VISIBLE     ', 'FUTURE      '], array_column(array_column($commandLines, 'params'), 'command'));
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

        new UnifiedHelpFormatter()->showCommandHelp($context, $command);

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'MemoServ']], $context->replies);
        self::assertContains([
            'key' => 'help.subcommand_line',
            'params' => ['command' => 'EMAIL     ', 'description' => 'translated:set.email.short'],
        ], $context->replies);
        self::assertContains([
            'key' => 'help.syntax_label',
            'params' => ['syntax' => 'translated:set.syntax'],
        ], $context->replies);
    }

    #[Test]
    public function rendersSubcommandHelpWithOptionalDetails(): void
    {
        $context = new MemoServHelpFormatterContext();

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
            'help.footer',
        ], array_column($context->replies, 'key'));
        self::assertSame('HELP SET EMAIL', $context->replies[0]['params']['title']);
    }

    #[Test]
    public function rendersCanonicalColorsFromAllMemoServTranslationCatalogs(): void
    {
        foreach (['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'] as $locale) {
            $catalog = Yaml::parseFile(dirname(__DIR__, 6) . '/translations/memoserv.' . $locale . '.yaml');
            self::assertIsArray($catalog);

            $helpTranslations = $catalog['help'] ?? null;
            self::assertIsArray($helpTranslations, $locale);
            self::assertSame('%bot%', $helpTranslations['header_title'] ?? null, $locale);
            self::assertArrayHasKey('header', $helpTranslations, $locale);
            foreach ($this->flattenStrings($helpTranslations) as $translation) {
                preg_match_all('/\x03(\d{1,2})/', $translation, $matches);
                foreach ($matches[1] as $color) {
                    self::assertContains($color, ['03', '04', '06', '07', '10', '14'], $locale . ' uses non-canonical HELP color ' . $color);
                }
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
            $formatter->showGeneralHelp($context);
            $formatter->showCommandHelp($context, $command);

            foreach ($context->renderedReplies as $renderedReply) {
                self::assertDoesNotMatchRegularExpression('/%[^%]+%/', $renderedReply, $locale);
            }

            self::assertStringContainsString("\x02\x0306● MemoServ\x03\x0F", $context->renderedReplies[0], $locale);
            self::assertStringContainsString("\x0314─────────────────────────────\x03\x0F", $context->renderedReplies[0], $locale);

            $commandRow = $this->renderedReplyFor($context, 'help.command_line');
            self::assertMatchesRegularExpression('/\x0310›\x03\x0F \x02\x0303IGNORE\s+\x03\x0F.+/u', $commandRow, $locale);

            $generalFooter = $this->renderedReplyFor($context, 'help.general_footer');
            self::assertMatchesRegularExpression('/\x0310ℹ\x03\x0F.*\x0303\/msg MemoServ HELP.*\x03\x0F/u', $generalFooter, $locale);

            $subcommandFooter = $this->renderedReplyFor($context, 'help.set_sub_footer');
            self::assertMatchesRegularExpression('/\x0310ℹ\x03\x0F.*\x0303\/msg MemoServ HELP IGNORE <[^>]+>\x03\x0F/u', $subcommandFooter, $locale);

            $syntax = $this->renderedReplyFor($context, 'help.syntax_label');
            self::assertMatchesRegularExpression('/\x02\x0306.+\x03\x0F \x02\x0303.+\x03\x0F/u', $syntax, $locale);

            $formatter->showSubCommandHelp($context, 'IGNORE', [
                'name' => 'ADD',
                'help_key' => 'ignore.add.help',
                'syntax_key' => 'ignore.add.syntax',
            ]);
            $subcommandHeader = null;
            foreach ($context->replies as $index => $reply) {
                if ('help.header' === $reply['key'] && 'HELP IGNORE ADD' === $reply['params']['title']) {
                    $subcommandHeader = $context->renderedReplies[$index];
                    break;
                }
            }
            self::assertIsString($subcommandHeader, $locale);
            self::assertStringContainsString("\x02\x0306● HELP IGNORE ADD\x03\x0F", $subcommandHeader, $locale);
        }
    }

    /** @return list<string> */
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

    private function renderedReplyFor(MemoServHelpFormatterContext $context, string $key): string
    {
        foreach ($context->replies as $index => $reply) {
            if ($key === $reply['key']) {
                return $context->renderedReplies[$index];
            }
        }

        self::fail('No reply found for ' . $key);
    }
}

final class MemoServHelpFormatterContext implements HelpFormatterContextInterface
{
    /** @var list<array{key: string, params: array<string, mixed>}> */
    public array $replies = [];

    /** @var list<string> */
    public array $rawReplies = [];

    /** @var list<string> */
    public array $renderedReplies = [];

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
        private readonly ?array $translations = null,
    ) {}

    public function reply(string $key, array $params = []): void
    {
        $this->replies[] = ['key' => $key, 'params' => $params];
        $this->renderedReplies[] = $this->translate($key, $params);
    }

    public function replyRaw(string $message): void
    {
        $this->rawReplies[] = $message;
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->translate($key, $params);
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
            $replace['%' . trim((string) $name, '%') . '%'] = is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
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
