<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Command\HelpCommand;
use App\OperServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;
use function is_array;
use function is_scalar;
use function is_string;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    #[Test]
    public function exposesPublicHelpMetadata(): void
    {
        $command = new HelpCommand(new UnifiedHelpFormatter());

        self::assertSame('HELP', $command->getName());
        self::assertSame(['?'], $command->getAliases());
        self::assertSame(0, $command->getMinArgs());
        self::assertSame('help.syntax', $command->getSyntaxKey());
        self::assertSame('help.help', $command->getHelpKey());
        self::assertSame(99, $command->getOrder());
        self::assertSame('help.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
    }

    #[Test]
    public function rejectsSenderWithoutOperatorAuthorization(): void
    {
        $notifier = new HelpNotifier();
        $authorization = $this->authorization(false);

        new HelpCommand(new UnifiedHelpFormatter())->execute($this->context([], [], $notifier, $authorization));

        self::assertSame(['error.oper_only'], $notifier->messages);
    }

    #[Test]
    public function emptyArgumentsShowGeneralHelpAndFooter(): void
    {
        $notifier = new HelpNotifier();
        $help = new HelpFixtureCommand('HELP');
        $visible = new HelpFixtureCommand('VISIBLE');

        new HelpCommand(new UnifiedHelpFormatter())->execute(
            $this->context([], [$help, $visible], $notifier, $this->authorization(true)),
        );

        self::assertSame([implode("\n", [
            'help.header',
            'help.intro',
            ' ',
            'help.general_header',
            ' ',
            'help.general_footer',
            'help.command_line',
            'help.footer',
        ])], $notifier->messages);
    }

    #[Test]
    public function unknownOrUnauthorizedCommandUsesUnknownPresentation(): void
    {
        $notifier = new HelpNotifier();
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('ircOperator')->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus));
        $authorization->method('permission')->willReturn(AuthorizationDecision::denied());
        $restricted = new HelpFixtureCommand('RESTRICTED', permission: 'operserv.restricted');
        $command = new HelpCommand(new UnifiedHelpFormatter());

        $command->execute($this->context(['MISSING'], [$restricted], $notifier, $authorization));
        $command->execute($this->context(['RESTRICTED'], [$restricted], $notifier, $authorization));

        self::assertSame(['help.unknown_command', 'help.unknown_command'], $notifier->messages);
    }

    #[Test]
    public function knownCommandAndSubcommandRenderTheirHelp(): void
    {
        $notifier = new HelpNotifier();
        $target = new HelpFixtureCommand('TARGET', subcommands: [[
            'name' => 'SET',
            'desc_key' => 'target.set.short',
            'help_key' => 'target.set.help',
            'syntax_key' => 'target.set.syntax',
        ]]);
        $command = new HelpCommand(new UnifiedHelpFormatter());
        $authorization = $this->authorization(true);

        $command->execute($this->context(['target'], [$target], $notifier, $authorization));
        self::assertCount(1, $notifier->messages);
        self::assertContains('target.help', explode("\n", $notifier->messages[0]));

        $notifier = new HelpNotifier();
        $command->execute($this->context(['target', 'set'], [$target], $notifier, $authorization));
        self::assertCount(1, $notifier->messages);
        self::assertContains('target.set.help', explode("\n", $notifier->messages[0]));

        $notifier = new HelpNotifier();
        $command->execute($this->context(['target', 'unknown'], [$target], $notifier, $authorization));
        self::assertCount(1, $notifier->messages);
        self::assertContains('target.help', explode("\n", $notifier->messages[0]));
    }

    #[Test]
    public function operOnlyCommandWithoutPermissionUsesOperatorDecision(): void
    {
        $notifier = new HelpNotifier();
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('ircOperator')->willReturnOnConsecutiveCalls(
            AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus),
            AuthorizationDecision::denied(),
        );

        new HelpCommand(new UnifiedHelpFormatter())->execute($this->context(
            ['OPER'],
            [new HelpFixtureCommand('OPER', operOnly: true)],
            $notifier,
            $authorization,
        ));

        self::assertSame(['help.unknown_command'], $notifier->messages);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function helpArguments(): iterable
    {
        yield 'general' => [[]];
        yield 'command' => [['TARGET']];
        yield 'subcommand' => [['TARGET', 'SET']];
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('helpArguments')]
    public function renderingFailureSendsNoPartialHelp(array $arguments): void
    {
        $failure = new RuntimeException('Translation failed at the final footer.');
        $notifier = new HelpNotifier();
        $target = new HelpFixtureCommand('TARGET', subcommands: [[
            'name' => 'SET',
            'desc_key' => 'target.set.short',
            'help_key' => 'target.set.help',
            'syntax_key' => 'target.set.syntax',
        ]]);
        $context = $this->context(
            $arguments,
            [$target],
            $notifier,
            $this->authorization(true),
            new HelpTranslation(failure: $failure),
        );

        try {
            new HelpCommand(new UnifiedHelpFormatter())->execute($context);
            self::fail('The translation failure must propagate synchronously.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame([], $notifier->messages);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('helpArguments')]
    public function transportFailurePropagatesSynchronously(array $arguments): void
    {
        $failure = new RuntimeException('Connection write failed.');
        $notifier = new HelpNotifier();
        $notifier->failure = $failure;
        $target = new HelpFixtureCommand('TARGET', subcommands: [[
            'name' => 'SET',
            'desc_key' => 'target.set.short',
            'help_key' => 'target.set.help',
            'syntax_key' => 'target.set.syntax',
        ]]);
        $context = $this->context($arguments, [$target], $notifier, $this->authorization(true));

        try {
            new HelpCommand(new UnifiedHelpFormatter())->execute($context);
            self::fail('The send failure must propagate during HELP execution.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }
        self::assertSame([], $notifier->messages);
    }

    #[Test]
    public function rendersLocalizedHeaderRowsAndErrorsWithoutStylesInCommandPhp(): void
    {
        $notifier = new HelpNotifier();
        $command = new HelpCommand(new UnifiedHelpFormatter());
        $translation = new HelpTranslation($this->englishTranslationEntries());

        $command->execute($this->context(
            [],
            [new HelpFixtureCommand('ROLE')],
            $notifier,
            $this->authorization(true),
            $translation,
        ));

        self::assertCount(1, $notifier->messages);
        $generalHelp = $notifier->messages[0];
        self::assertSame(
            "\x02\x0307● OperServ\x03\x0F \x0314" . str_repeat('─', 29) . "\x03\x0F",
            explode("\n", $generalHelp)[0],
        );
        self::assertStringContainsString(
            "\x0310›\x03\x0F \x02\x0303ROLE        \x03\x0FManage operator roles.",
            $generalHelp,
        );

        $notifier = new HelpNotifier();
        $command->execute($this->context(['MISSING'], [], $notifier, $this->authorization(true), $translation));
        $error = implode("\n", $notifier->messages);
        self::assertStringContainsString("\x0307✗ Unknown command\x03\x0F \x02\x0303MISSING\x03\x0F", $error);
        self::assertStringContainsString("\x0303/msg OperServ HELP\x03\x0F", $error);

        $commandSource = file_get_contents(dirname(__DIR__, 6) . '/src/OperServ/Adapter/In/Irc/Command/HelpCommand.php');
        self::assertIsString($commandSource);
        foreach (['\\x03', '\\x02', '\\x0F', "\x03", "\x02", "\x0F", '●', '◆', '›', 'ℹ', '⚠', '✗', '─'] as $style) {
            self::assertStringNotContainsString($style, $commandSource);
        }
    }

    /** @param list<OperServCommandInterface> $commands
     * @param list<string> $arguments
     */
    private function context(
        array $arguments,
        array $commands,
        HelpNotifier $notifier,
        OperatorAuthorizationQuery $authorization,
        ?HelpTranslation $translation = null,
    ): OperServContext {
        return new OperServContext(
            new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            new NickAccountData(42, 'Oper', 'en'),
            'HELP',
            $arguments,
            $notifier,
            $translation ?? new HelpTranslation(),
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry($commands),
            new ServiceNicknameRegistry([]),
            $authorization,
        );
    }

    /** @return array<string, string> */
    private function englishTranslationEntries(): array
    {
        $catalog = Yaml::parseFile(dirname(__DIR__, 6) . '/translations/operserv.en.yaml');
        self::assertIsArray($catalog);

        $entries = [];
        $flatten = static function (array $values, string $prefix = '') use (&$flatten, &$entries): void {
            foreach ($values as $key => $value) {
                $path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;
                if (is_array($value)) {
                    $flatten($value, $path);
                } elseif (is_string($value)) {
                    $entries[$path] = $value;
                }
            }
        };
        $flatten($catalog);

        return $entries;
    }

    private function authorization(bool $granted): OperatorAuthorizationQuery
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('ircOperator')->willReturn($granted
            ? AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus)
            : AuthorizationDecision::denied());

        return $authorization;
    }
}

final readonly class HelpFixtureCommand implements OperServCommandInterface
{
    /** @param list<array{name: string, desc_key: string, help_key: string, syntax_key: string}> $subcommands */
    public function __construct(
        private string $name,
        private ?string $permission = null,
        private bool $operOnly = false,
        private array $subcommands = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return strtolower($this->name) . '.syntax';
    }

    public function getHelpKey(): string
    {
        return strtolower($this->name) . '.help';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return strtolower($this->name) . '.short';
    }

    public function getSubCommandHelp(): array
    {
        return $this->subcommands;
    }

    public function isOperOnly(): bool
    {
        return $this->operOnly;
    }

    public function getRequiredPermission(): ?string
    {
        return $this->permission;
    }

    public function execute(OperServContext $context): void {}
}

final class HelpNotifier implements OperServNotifierInterface
{
    public ?RuntimeException $failure = null;

    /** @var list<string> */
    public array $messages = [];

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->messages[] = $message;
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        if (null !== $this->failure) {
            throw $this->failure;
        }
        $this->messages[] = $message;
    }

    public function getNick(): string
    {
        return 'OperServ';
    }

    public function getUid(): string
    {
        return '001OS';
    }

    public function getServiceKey(): string
    {
        return 'operserv';
    }
}

final class HelpTranslation implements TranslatorInterface
{
    /** @param array<string, string> $translations */
    public function __construct(
        private readonly array $translations = [],
        private readonly ?RuntimeException $failure = null,
    ) {}

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ('help.footer' === $id && null !== $this->failure) {
            throw $this->failure;
        }
        $replacements = [];
        foreach ($parameters as $key => $value) {
            if (is_scalar($value)) {
                $replacements[(string) $key] = (string) $value;
            }
        }

        return strtr($this->translations[$id] ?? $id, $replacements);
    }

    public function getLocale(): string
    {
        return 'en';
    }
}
