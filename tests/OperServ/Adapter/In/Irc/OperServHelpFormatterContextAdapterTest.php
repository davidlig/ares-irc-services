<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServHelpFormatterContextAdapter;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Application\Help\HelpableCommandInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperServHelpFormatterContextAdapter::class)]
final class OperServHelpFormatterContextAdapterTest extends TestCase
{
    #[Test]
    public function delegatesOutputTranslationAndCommandEnumeration(): void
    {
        $command = new FormatterCommand('PUBLIC');
        $notifier = new FormatterNotifier();
        $translator = new FormatterTranslation();
        $adapter = new OperServHelpFormatterContextAdapter($this->context([$command], $notifier, $translator));

        $adapter->reply('help.key', ['value' => 'translated']);
        $adapter->replyRaw('raw line');

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

    /** @param list<OperServCommandInterface> $commands */
    private function context(
        array $commands = [],
        ?FormatterNotifier $notifier = null,
        ?FormatterTranslation $translator = null,
        ?OperatorAuthorizationQuery $authorization = null,
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
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return '';
    }

    public function getHelpKey(): string
    {
        return '';
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

final class FormatterTranslation implements TranslationInterface
{
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }
}
