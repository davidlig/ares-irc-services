<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Adapter\In\Irc\OperServService;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(OperServService::class)]
#[CoversClass(OperServContext::class)]
#[CoversClass(OperServCommandRegistry::class)]
final class OperServServiceTest extends TestCase
{
    #[Test]
    public function ignoresBlankInput(): void
    {
        $notifier = new RecordingOperServNotifier();
        $this->service([], $notifier)->dispatch('  ', $this->sender());

        self::assertSame([], $notifier->messages);
    }

    #[Test]
    public function unknownCommandUsesDefaultLanguageAndConfiguredMessageType(): void
    {
        $notifier = new RecordingOperServNotifier();
        $this->service([], $notifier, prefersPrivate: true)->dispatch('missing', $this->sender());

        self::assertSame([['001AAA', 'unknown_command', 'PRIVMSG']], $notifier->messages);
    }

    #[Test]
    public function deniesPermissionWithoutAnIdentifiedAccountBeforeCallingAuthorization(): void
    {
        $notifier = new RecordingOperServNotifier();
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::never())->method('permission');
        $command = new RecordingOperServCommand('SECURE', 'operserv.secure');

        $this->service([$command], $notifier, $authorization, account: null)->dispatch('secure', $this->sender());

        self::assertFalse($command->executed);
        self::assertSame([['001AAA', 'error.permission_denied', 'NOTICE']], $notifier->messages);
    }

    #[Test]
    public function delegatesPermissionDecisionWithAllActorFacts(): void
    {
        $notifier = new RecordingOperServNotifier();
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())->method('permission')->with(
            self::callback(static fn (OperatorActor $actor): bool => 'Root' === $actor->nickname
                && 42 === $actor->identifiedAccountId
                && $actor->identified
                && !$actor->ircOperator),
            'operserv.secure',
        )->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity));
        $command = new RecordingOperServCommand('SECURE', 'operserv.secure');

        $this->service([$command], $notifier, $authorization)->dispatch('secure argument', $this->sender('Root', true, false));

        self::assertTrue($command->executed);
        self::assertSame(['argument'], $command->arguments);
    }

    #[Test]
    public function mapsIdentifiedAndRootDenialsToSpecificPresentation(): void
    {
        $notifier = new RecordingOperServNotifier();
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('identifiedAccount')->willReturn(AuthorizationDecision::denied());
        $authorization->method('root')->willReturn(AuthorizationDecision::denied());

        $this->service([
            new RecordingOperServCommand('IDENTIFIED', OperatorAuthorizationAttribute::IDENTIFIED),
            new RecordingOperServCommand('ROOT', OperatorAuthorizationAttribute::ROOT),
        ], $notifier, $authorization)->dispatch('identified', $this->sender(identified: true));
        $this->service([
            new RecordingOperServCommand('IDENTIFIED', OperatorAuthorizationAttribute::IDENTIFIED),
            new RecordingOperServCommand('ROOT', OperatorAuthorizationAttribute::ROOT),
        ], $notifier, $authorization)->dispatch('root', $this->sender(identified: true));

        self::assertSame([
            ['001AAA', 'error.not_identified', 'NOTICE'],
            ['001AAA', 'error.root_only', 'NOTICE'],
        ], $notifier->messages);
    }

    #[Test]
    public function operOnlyCommandUsesTheCentralOperatorDecision(): void
    {
        $notifier = new RecordingOperServNotifier();
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())->method('ircOperator')->willReturn(AuthorizationDecision::denied());
        $command = new RecordingOperServCommand('OPER', null, true);

        $this->service([$command], $notifier, $authorization)->dispatch('oper', $this->sender(identified: true, oper: true));

        self::assertFalse($command->executed);
        self::assertSame([['001AAA', 'error.oper_only', 'NOTICE']], $notifier->messages);
    }

    #[Test]
    public function validatesMinimumArgumentsAfterAuthorization(): void
    {
        $notifier = new RecordingOperServNotifier();
        $command = new RecordingOperServCommand('ARGS', null, false, 2);

        $this->service([$command], $notifier)->dispatch('args one', $this->sender());

        self::assertFalse($command->executed);
        self::assertSame([['001AAA', 'error.syntax', 'NOTICE']], $notifier->messages);
    }

    #[Test]
    public function contextExposesOnlyAdapterUtilities(): void
    {
        $notifier = new RecordingOperServNotifier();
        $registry = new OperServCommandRegistry([]);
        $context = new OperServContext(
            $this->sender(identified: true),
            new NickAccountData(42, 'Test', 'es', 'Europe/Madrid'),
            'TEST',
            [],
            $notifier,
            new KeyTranslation(),
            'es',
            'Europe/Madrid',
            'NOTICE',
            $registry,
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
        );

        $context->replyRaw('raw');

        self::assertSame(42, $context->senderAccountId());
        self::assertSame($registry, $context->commandRegistry());
        self::assertSame('OperServ', $context->getBotName());
        self::assertSame('key', $context->trans('key'));
        self::assertSame('01/01/2026 13:00 CET', $context->formatDate(new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('UTC'))));
        self::assertSame('—', $context->formatDate(null));
        self::assertSame([['001AAA', 'raw', 'NOTICE']], $notifier->messages);
    }

    /** @return iterable<string, array{string}> */
    public static function translationLocales(): iterable
    {
        yield 'direct locale' => ['en'];
        yield 'parent locale fallback' => ['en_US'];
        yield 'configured fallback' => ['zz'];
    }

    #[Test]
    #[DataProvider('translationLocales')]
    public function commonTranslationsTakePrecedenceAndMissingKeysFallBackToOperServ(string $language): void
    {
        $translator = new Translator($language);
        $translator->addLoader('array', new ArrayLoader());
        $translator->setFallbackLocales(['en']);
        $translator->addResource('array', ['unknown_command' => 'Shared unknown-command message'], 'en', 'common');
        $translator->addResource('array', ['unknown_command' => 'Local unknown-command message', 'error.permission_denied' => 'OperServ permission message'], 'en', 'operserv');
        $notifier = new RecordingOperServNotifier();

        $this->service([], $notifier, account: null, translator: $translator, language: $language)->dispatch('missing', $this->sender());
        $this->service(
            [new RecordingOperServCommand('SECURE', 'operserv.secure')],
            $notifier,
            account: null,
            translator: $translator,
            language: $language,
        )->dispatch('secure', $this->sender());

        self::assertSame([
            ['001AAA', 'Shared unknown-command message', 'NOTICE'],
            ['001AAA', 'OperServ permission message', 'NOTICE'],
        ], $notifier->messages);
        $context = new OperServContext(
            $this->sender(),
            null,
            'HELP',
            [],
            $notifier,
            $translator,
            $language,
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
        );
        self::assertSame('Shared unknown-command message', $context->trans('unknown_command'));
        self::assertSame('OperServ permission message', $context->trans('error.permission_denied'));
        self::assertSame('missing.key', $context->trans('missing.key'));
    }

    /** @param list<OperServCommandInterface> $commands */
    private function service(
        array $commands,
        RecordingOperServNotifier $notifier,
        ?OperatorAuthorizationQuery $authorization = null,
        ?NickAccountData $account = new NickAccountData(42, 'Test', 'en'),
        bool $prefersPrivate = false,
        ?TranslatorInterface $translator = null,
        string $language = 'en',
    ): OperServService {
        $accounts = $this->createStub(NickAccountQuery::class);
        $accounts->method('findAccountByNick')->willReturn($account);
        $languages = $this->createStub(UserLanguageQuery::class);
        $languages->method('resolveFromAccount')->willReturn($language);
        $preferences = $this->createStub(UserMessagePreferenceQuery::class);
        $preferences->method('prefersPrivateMessages')->willReturn($prefersPrivate);

        return new OperServService(
            new OperServCommandRegistry($commands),
            $accounts,
            $languages,
            $preferences,
            $notifier,
            $translator ?? new KeyTranslation(),
            new ServiceNicknameRegistry([]),
            $authorization ?? $this->createStub(OperatorAuthorizationQuery::class),
            $language,
        );
    }

    private function sender(string $nickname = 'Test', bool $identified = false, bool $oper = false): SenderView
    {
        return new SenderView('001AAA', $nickname, 'ident', 'host', 'cloak', 'ip', $identified, $oper, '001');
    }
}

final class RecordingOperServCommand implements OperServCommandInterface
{
    public bool $executed = false;

    /** @var list<string> */
    public array $arguments = [];

    /** @param list<string> $aliases */
    public function __construct(
        private readonly string $name,
        private readonly ?string $permission,
        private readonly bool $operOnly = false,
        private readonly int $minArgs = 0,
        private readonly array $aliases = [],
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getAliases(): array
    {
        return $this->aliases;
    }

    public function getMinArgs(): int
    {
        return $this->minArgs;
    }

    public function getSyntaxKey(): string
    {
        return 'command.syntax';
    }

    public function getHelpKey(): string
    {
        return 'command.help';
    }

    public function getExampleKey(): string
    {
        return 'command.example';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'command.short';
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

    public function execute(OperServContext $context): void
    {
        $this->executed = true;
        $this->arguments = $context->args;
    }
}

final class RecordingOperServNotifier implements OperServNotifierInterface
{
    /** @var list<array{string, string, string}> */
    public array $messages = [];

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->messages[] = [$targetUidOrNick, $message, 'NOTICE'];
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->messages[] = [$targetUidOrNick, $message, $messageType];
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

final class KeyTranslation implements TranslatorInterface
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
