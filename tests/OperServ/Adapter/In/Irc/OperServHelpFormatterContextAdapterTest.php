<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServHelpFormatterContextAdapter;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function iterator_to_array;
use function strtr;

#[CoversClass(OperServHelpFormatterContextAdapter::class)]
#[CoversClass(OperServContext::class)]
final class OperServHelpFormatterContextAdapterTest extends TestCase
{
    #[Test]
    public function rendersStructuredHelpNavigationExamplesAndRoleOptions(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params): string {
            $templates = [
                'help.general_header' => 'Commands', 'help.options_header' => 'Options', 'help.group_header' => '%group%',
                'help.command_line' => '%command%: %description%', 'help.syntax_label' => 'Syntax %syntax%',
                'help.general_footer' => 'Use %help_command%', 'help.set_sub_footer' => 'Use %help_command%',
                'help.general_help_command' => 'HELP <command>', 'help.option_help_command' => 'HELP %command% <option>',
                'help.detail_help_command' => 'HELP <command>', 'help.detail_help_option_command' => 'HELP <command> <option>',
                'help.help' => '%help_command% / %help_option_command%',
                'role.perms.help' => '%options_header% %option_LIST% %assigned_status% %unassigned_status%',
            ];

            return strtr($templates[$id] ?? $id, $params);
        });
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(components: [
            'section' => new Appearance(prefix: 'S:'), 'group' => new Appearance(prefix: 'G:'),
            'command_row' => new Appearance(prefix: 'R:'), 'command' => new Appearance(prefix: '[', suffix: ']'),
            'syntax' => new Appearance(prefix: 'Y:'), 'navigation' => new Appearance(prefix: 'N:'),
            'example' => new Appearance(prefix: '> '), 'selected' => new Appearance(prefix: '+'), 'unselected' => new Appearance(prefix: '-'),
        ])));
        $adapter = new OperServHelpFormatterContextAdapter($this->context(translator: $translator, renderer: $renderer));
        self::assertSame('S:Commands', $adapter->trans('help.general_header'));
        self::assertSame('G:Roles', $adapter->trans('help.group_header', ['group' => 'Roles']));
        self::assertSame('R:[ROLE]: Manage', $adapter->trans('help.command_line', ['command' => 'ROLE', 'description' => 'Manage']));
        self::assertSame('Y:Syntax [ROLE LIST]', $adapter->trans('help.syntax_label', ['syntax' => 'ROLE LIST']));
        self::assertSame('N:Use [HELP <command>]', $adapter->trans('help.general_footer'));
        self::assertSame('N:Use [HELP ROLE <option>]', $adapter->trans('help.set_sub_footer', ['command' => 'ROLE']));
        self::assertSame('[HELP <command>] / [HELP <command> <option>]', $adapter->trans('help.help'));
        self::assertSame('S:Options R:[LIST      ] + -', $adapter->trans('role.perms.help'));
        self::assertSame('> /msg OperServ [ROLE LIST]', $adapter->renderExample('%command_prefix%ROLE LIST'));
        self::assertSame('legacy text', $adapter->renderExample('legacy text'));
        self::assertStringContainsString('Help', $adapter->trans('help.header', ['title' => 'Help']));
        self::assertNotSame('', $adapter->trans('help.footer'));
        try {
            $adapter->trans('help.command_line', ['command' => ['invalid']]);
            self::fail('A non-text HELP parameter must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('HELP parameters must be text.', $exception->getMessage());
        }
    }

    #[Test]
    public function delegatesOutputTranslationAndCommandEnumeration(): void
    {
        $command = new FormatterCommand('PUBLIC');
        $notifier = new FormatterNotifier();
        $translator = new FormatterTranslation();
        $adapter = new OperServHelpFormatterContextAdapter($this->context([$command], $notifier, $translator));

        $adapter->reply('help.key', ['value' => 'translated']);
        $adapter->replyRaw('raw line');

        self::assertSame('OperServ', $adapter->getBotName());
        self::assertSame(['help.key', 'raw line'], $notifier->messages);
        self::assertSame('translated.key', $adapter->trans('translated.key'));
        self::assertSame([$command], iterator_to_array($adapter->getCommandsForGeneralHelp()));
        self::assertSame([], iterator_to_array($adapter->getIrcopCommands()));
        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function rejectsForeignHelpCommands(): void
    {
        $adapter = new OperServHelpFormatterContextAdapter($this->context());
        $command = $this->createStub(HelpableCommandInterface::class);

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($command));
    }

    #[Test]
    public function checksExplicitPermissionThroughCentralAuthorization(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())->method('permission')->with(self::anything(), 'operserv.test')
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RolePermission));
        $adapter = new OperServHelpFormatterContextAdapter($this->context(authorization: $authorization));

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp(new FormatterCommand('SECURE', 'operserv.test')));
    }

    #[Test]
    public function publicCommandIsVisibleWithoutAuthorization(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::never())->method('ircOperator');
        $adapter = new OperServHelpFormatterContextAdapter($this->context(authorization: $authorization));

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp(new FormatterCommand('PUBLIC')));
    }

    #[Test]
    public function operOnlyCommandUsesCentralOperatorAuthorization(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())->method('ircOperator')->with(self::anything())
            ->willReturn(AuthorizationDecision::denied());
        $adapter = new OperServHelpFormatterContextAdapter($this->context(authorization: $authorization));

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp(new FormatterCommand('OPER', operOnly: true)));
    }

    #[Test]
    public function canViewPublicCommandAndExposesHelpGroups(): void
    {
        $adapter = new OperServHelpFormatterContextAdapter($this->context());

        self::assertTrue($adapter->canViewCommandInHelp(new FormatterCommand('PUBLIC')));
        self::assertSame('help.group.operators_roles', $adapter->getHelpGroups()[0]['group_key']);
    }

    #[Test]
    public function translatesCommonFirstButHonorsExplicitDomains(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['shared.key' => 'Common %value% via %bot%'], 'en', 'common');
        $translator->addResource('array', ['shared.key' => 'Service %value%'], 'en', 'operserv');
        $translator->addResource('array', ['drop.short' => 'Explicit %value% via %bot%'], 'en', 'chanserv');
        $context = $this->context(translator: $translator);
        $adapter = new OperServHelpFormatterContextAdapter($context);

        self::assertSame('Common x via OperServ', $adapter->trans('shared.key', ['value' => 'x']));
        self::assertSame('Explicit x via OperServ', $context->transInDomain('drop.short', 'chanserv', ['value' => 'x']));
    }

    /** @param list<OperServCommandInterface> $commands */
    private function context(
        array $commands = [],
        ?FormatterNotifier $notifier = null,
        ?TranslatorInterface $translator = null,
        ?OperatorAuthorizationQuery $authorization = null,
        ?IrcRenderer $renderer = null,
    ): OperServContext {
        return new OperServContext(
            new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            new NickAccountData(42, 'Oper', 'en'),
            'HELP',
            [],
            $notifier ?? new FormatterNotifier(),
            $translator ?? new FormatterTranslation(),
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry($commands),
            new ServiceNicknameRegistry([]),
            $authorization ?? $this->createStub(OperatorAuthorizationQuery::class),
            $renderer,
        );
    }
}

final readonly class FormatterCommand implements OperServCommandInterface
{
    public function __construct(
        private string $name,
        private ?string $permission = null,
        private bool $operOnly = false,
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
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('');
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return '';
    }

    public function getExampleKey(): string
    {
        return $this->name . '.example';
    }

    public function getOrder(): int
    {
        return 0;
    }

    public function getShortDescKey(): string
    {
        return '';
    }

    public function getSubCommandHelp(): array
    {
        return [];
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

final class FormatterNotifier implements OperServNotifierInterface
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

final class FormatterTranslation implements TranslatorInterface
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
