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

    private const array ALLOWED_HELP_COLORS = ['03', '04', '06', '07', '10', '14'];

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
        self::assertContains('help.ircop_header', array_column($context->replies, 'key'));
        self::assertSame('help.header', $context->replies[0]['key']);
        self::assertSame('translated:help.header_title', $context->replies[0]['params']['title']);
        self::assertContains('help.group_header', array_column($context->replies, 'key'));
        self::assertContains('help.subgroup_header', array_column($context->replies, 'key'));
    }

    #[Test]
    public function rendersUngroupedCommandAndGeneralFooterWithoutAdminCommands(): void
    {
        $command = new ChanServHelpableCommand('FUTURE', 1);
        $context = new ChanServHelpFormatterContext(
            commands: [$command],
            visibleCommands: ['FUTURE'],
        );

        new UnifiedHelpFormatter()->showGeneralHelp($context);

        $keys = array_column($context->replies, 'key');
        self::assertContains('help.general_footer', $keys);
        self::assertNotContains('help.ircop_header', $keys);
        $commandLines = array_values(array_filter(
            $context->replies,
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

        new UnifiedHelpFormatter()->showCommandHelp($context, $command);

        self::assertContains(['key' => 'set.help', 'params' => ['service' => 'ChanServ']], $context->replies);
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
        $context = new ChanServHelpFormatterContext();

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
    public function definesHeaderTemplateAndPlaceholdersInEveryLocale(): void
    {
        $context = new ChanServHelpFormatterContext();
        new UnifiedHelpFormatter()->sendHeader($context, 'HELP SET EMAIL');

        self::assertSame([
            'key' => 'help.header',
            'params' => ['title' => 'HELP SET EMAIL', 'separator' => str_repeat('─', 23)],
        ], $context->replies[0]);

        $expectedTemplate = "\x02\x0306● %title%\x03\x0F \x0314%separator%\x03";
        foreach (self::LOCALES as $locale) {
            $catalog = $this->loadCatalog($locale);
            self::assertSame($expectedTemplate, $catalog['help']['header'] ?? null, $locale);
        }
    }

    #[Test]
    public function allLocaleHelpTemplatesUseOnlyCanonicalStructuralColors(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalog = $this->loadCatalog($locale);
            $help = $catalog['help'] ?? null;

            self::assertIsArray($help, $locale . ' must define the help subtree');
            $this->assertHelpColorsAllowed($help, $locale);
        }
    }

    #[Test]
    public function rendersCanonicalHelpColorsAndLeavesDescriptionsUncolored(): void
    {
        $catalog = $this->loadCatalog('en');
        $help = $catalog['help'];
        $context = new ChanServHelpFormatterContext(
            commands: [new ChanServHelpableCommand('VISIBLE', 1)],
            visibleCommands: ['VISIBLE'],
        );

        new UnifiedHelpFormatter()->showGeneralHelp($context);

        $title = 'translated:help.header_title';
        self::assertSame(
            "\x02\x0306● {$title}\x03\x0F \x0314" . str_repeat('─', max(0, 40 - 3 - mb_strlen($title))) . "\x03",
            $this->renderReply($context->replies, $help, 'help.header'),
        );
        self::assertSame("\x02\x0306Available commands:\x03\x0F", $this->renderReply($context->replies, $help, 'help.general_header'));
        self::assertSame("\x02\x0306◆ translated:help.group.public\x03\x0F", $this->renderReply($context->replies, $help, 'help.group_header'));

        $renderedCommand = $this->renderReply($context->replies, $help, 'help.command_line');
        self::assertSame("  \x0310›\x03 \x0303VISIBLE     \x03translated:visible.short", $renderedCommand);
        self::assertSame('translated:visible.short', substr($renderedCommand, strpos($renderedCommand, 'translated:visible.short')));

        $setContext = new ChanServHelpFormatterContext();
        new UnifiedHelpFormatter()->showCommandHelp($setContext, new ChanServHelpableCommand('SET', 1, [[
            'name' => 'EMAIL',
            'desc_key' => 'set.email.short',
            'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax',
        ]]));
        self::assertSame("\x02\x0306◆ Options:\x03\x0F", $this->renderReply($setContext->replies, $help, 'help.options_header'));
        self::assertSame("  \x0310›\x03 \x0303EMAIL     \x03translated:set.email.short", $this->renderReply($setContext->replies, $help, 'help.subcommand_line'));

        self::assertSame("\x0307⚠\x03 NOTE: Channels unused for more than 30 days are automatically removed.", $this->renderTemplate($help['intro_expiration'], ['days' => '30']));
        self::assertSame("\x0304✗\x03 Unknown command \x02FUTURE\x02. Use \x0303/msg ChanServ HELP\x03.", $this->renderTemplate($help['unknown_command'], ['command' => 'FUTURE', 'bot' => 'ChanServ']));
        self::assertSame("\x0314─────────────────────────────\x03", $help['footer']);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCatalog(string $locale): array
    {
        $catalog = Yaml::parseFile(dirname(__DIR__, 6) . '/translations/chanserv.' . $locale . '.yaml');
        self::assertIsArray($catalog, $locale . ' catalog must parse as a mapping');

        return $catalog;
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
