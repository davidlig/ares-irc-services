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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

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

        self::assertContains('help.general_header', $notifier->messages);
        self::assertContains('help.command_line', $notifier->messages);
        self::assertSame('help.footer', $notifier->messages[array_key_last($notifier->messages)]);
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
        self::assertContains('target.help', $notifier->messages);

        $notifier->messages = [];
        $command->execute($this->context(['target', 'set'], [$target], $notifier, $authorization));
        self::assertContains('target.set.help', $notifier->messages);

        $notifier->messages = [];
        $command->execute($this->context(['target', 'unknown'], [$target], $notifier, $authorization));
        self::assertContains('target.help', $notifier->messages);
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

    /** @param list<OperServCommandInterface> $commands
     * @param list<string> $arguments
     */
    private function context(
        array $arguments,
        array $commands,
        HelpNotifier $notifier,
        OperatorAuthorizationQuery $authorization,
    ): OperServContext {
        return new OperServContext(
            new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            new NickAccountData(42, 'Oper', 'en'),
            'HELP',
            $arguments,
            $notifier,
            new HelpTranslation(),
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry($commands),
            new ServiceNicknameRegistry([]),
            $authorization,
        );
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
    /** @var list<string> */
    public array $messages = [];

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->messages[] = $message;
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
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
    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
    }
}
