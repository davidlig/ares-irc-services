<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function dirname;

#[CoversClass(NickServContext::class)]
final class NickServContextTest extends TestCase
{
    #[Test]
    public function pendingIdentificationRendersIndentedCommandFragments(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'IDENTIFY', [], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks('CustomNick'), $renderer);
        $context->replyIdentifyPending('Alice');
        self::assertSame(["\x0304✗\x03\x0F El apodo \x02Alice\x03\x0F está pendiente de verificación de email.\n  Ejecuta /msg CustomNick \x02\x0303VERIFY <token>\x03\x0F o /msg CustomNick \x02\x0303RESEND\x03\x0F"], $messages);
    }

    #[Test]
    public function unidentifiedErrorKeepsConfiguredNickAndStyledCommand(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['presentation.error.not_identified' => 'Use /msg %nickserv% %identify%', 'presentation.identify' => 'IDENTIFY <nickname> <password>'], 'en', 'common');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('CustomNick');
        $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'INFO', [], $notifier, $translator, 'en', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), new ServiceNicknameRegistry([$provider]), $renderer);
        $context->replyNotIdentified();
        self::assertSame(["\x0304✗\x03\x0F Use /msg CustomNick \x02\x0303IDENTIFY <nickname> <password>\x03\x0F"], $messages);
    }

    #[Test]
    public function exposesIndependentSenderIdentityAndIrcOperatorFacts(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(42);
        $context = $this->createContext(
            $sender,
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            senderAccount: $account,
        );

        self::assertSame(42, $context->getSenderAccountId());
        self::assertSame('OperNick', $context->getSenderNickname());
        self::assertTrue($context->isSenderIdentified());
        self::assertTrue($context->isSenderIrcOperator());
    }

    #[Test]
    public function getLanguageAndGetTimezoneReturnInjectedValues(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        self::assertSame('en', $context->getLanguage());
        self::assertSame('Europe/Madrid', $context->getTimezone());
    }

    #[Test]
    public function getRegistryReturnsInjectedRegistry(): void
    {
        $registry = new NickServCommandRegistry([]);
        $serviceNicks = $this->createServiceNicks('NickServ');
        $context = new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $serviceNicks,
        );

        self::assertSame($registry, $context->getRegistry());
    }

    #[Test]
    public function getNotifierGetPendingVerificationGetRecoveryTokenReturnInjected(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $pending = new PendingVerificationRegistry();
        $recovery = new RecoveryTokenRegistry();
        $serviceNicks = $this->createServiceNicks('NickServ');
        $context = new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
            [],
            $notifier,
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            $pending,
            $recovery,
            $serviceNicks,
        );

        self::assertSame($notifier, $context->getNotifier());
        self::assertSame($pending, $context->getPendingVerificationRegistry());
        self::assertSame($recovery, $context->getRecoveryTokenRegistry());
    }

    #[Test]
    public function formatDateReturnsFormattedString(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );
        $date = new DateTimeImmutable('2024-06-15 14:30:00', new DateTimeZone('UTC'));

        $formatted = $context->formatDate($date);

        self::assertStringContainsString('15/06/2024', $formatted);
        self::assertMatchesRegularExpression('/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/', $formatted);
    }

    #[Test]
    public function formatDateReturnsDashWhenNull(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        self::assertSame('—', $context->formatDate(null));
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
    public function transDelegatesToTranslatorWithCatalogAndLocale(string $language): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = new Translator($language);
        $translator->addLoader('array', new ArrayLoader());
        $translator->setFallbackLocales(['en']);
        $translator->addResource('array', ['info.key' => 'Service translation'], 'en', 'nickserv');
        $translator->addResource('array', ['info.key' => 'Common translation'], 'en', 'common');
        $translator->addResource('array', ['service.only' => 'Service-only message'], 'en', 'nickserv');
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
            language: $language,
        );

        self::assertSame('Common translation', $context->trans('info.key'));
        self::assertSame('Service-only message', $context->trans('service.only'));
        self::assertSame('missing.key', $context->trans('missing.key'));
        self::assertSame('Common translation', $context->transIn('info.key', [], $language));
    }

    #[Test]
    public function transInUsesExplicitLanguageWhenProvided(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['key' => 'Traducido'], 'es', 'nickserv');
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        self::assertSame('Traducido', $context->transIn('key', [], 'es'));
    }

    #[Test]
    public function rendersSemanticReplyAfterLocalizingWithoutRecursivelyExpandingUserContent(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['example' => 'For %nickname% via %bot%'], 'en', 'nickserv');
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->expects(self::once())->method('sendMessage')->with(
            'UID1',
            "\x0303✔\x03\x0F For %bot% via CustomNick",
            'PRIVMSG',
        );
        $context = new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'PRIVMSG',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks('CustomNick'),
            new IrcRenderer(
                new FixedStyleProvider(
                    PresentationStyle::default(),
                ),
            ),
        );

        $context->reply('example', ['nickname' => '%bot%'], Role::Success);
    }

    #[Test]
    public function rendersDataRowsWithoutAddingAnOutcomeMarker(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['status.row' => 'Status: %value%'], 'en', 'nickserv');
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('UID1', 'Status: active', 'NOTICE');
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'STATUS', [], $notifier, $translator, 'en', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks('NickServ'), $renderer);
        $context->replyComponent(Component::Row, 'status.row', ['value' => 'active']);
    }

    #[Test]
    public function replySendsMessageWhenSenderIsSet(): void
    {
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        $context->reply('test.key');

        self::assertSame(['test.key'], $messages);
    }

    #[Test]
    public function replyDoesNotSendWhenSenderIsNull(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext(null, $notifier, $translator, []);

        $context->reply('test.key');
    }

    #[Test]
    public function replyRawSendsMessageWhenSenderIsSet(): void
    {
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        $context->replyRaw('Raw text');

        self::assertSame(['Raw text'], $messages);
    }

    #[Test]
    public function wrapParamsIsIdempotentForAlreadyWrappedKeys(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $translator = $this->createMock(TranslatorInterface::class);
        $calls = [];
        $translator->expects(self::once())
            ->method('trans')
            ->willReturnCallback(static function (string $key, array $params, string $domain, ?string $locale) use (&$calls): string {
                $calls[] = [$key, $params, $domain, $locale];

                return 'common' === $domain ? $key : 'Hello User';
            });
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        $context->reply('key', ['%nick%' => 'User']);

        self::assertSame(['nickserv'], array_column($calls, 2));
        self::assertSame('User', $calls[0][1]['%nick%']);
        self::assertSame('NickServ', $calls[0][1]['%bot%']);
    }

    #[Test]
    public function getSenderReturnsSenderView(): void
    {
        $sender = new SenderView('UID123', 'TestNick', 'ident', 'host', 'cloak', 'ip');
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $serviceNicks = $this->createServiceNicks('NickServ');

        $context = new NickServContext(
            $sender,
            null,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $serviceNicks,
        );

        self::assertSame($sender, $context->getSender());
    }

    #[Test]
    public function getSenderAccountReturnsAccount(): void
    {
        $sender = new SenderView('UID123', 'TestNick', 'ident', 'host', 'cloak', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $serviceNicks = $this->createServiceNicks('NickServ');

        $context = new NickServContext(
            $sender,
            $account,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $serviceNicks,
        );

        self::assertSame($account, $context->getSenderAccount());
    }

    #[Test]
    public function getSenderReturnsNullWhenSenderIsNull(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $serviceNicks = $this->createServiceNicks('NickServ');

        $context = new NickServContext(
            null,
            null,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $serviceNicks,
        );

        self::assertNull($context->getSender());
        self::assertNull($context->getSenderAccount());
        self::assertNull($context->getSenderAccountId());
    }

    #[Test]
    public function formatsDurationUsingCurrentLocaleCommonMessages(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'duration.unit.hour.singular' => 'hour',
            'duration.unit.second.singular' => 'second',
            'duration.unit.second.plural' => 'seconds',
            'duration.list_join' => '%prefix% and %last%',
            'duration.list_separator' => ', ',
        ], 'en', 'common');
        $context = $this->createContext(null, $this->createStub(NickServNotifierInterface::class), $translator);

        self::assertSame('1 hour and 1 second', $context->formatDuration(3601));
        self::assertSame('0 seconds', $context->formatDuration(0));
    }

    #[Test]
    public function formatsDurationUsingSpanishCommonWhenCurrentLocaleIsMissing(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['duration.unit.second.singular' => 'segundo'], 'es', 'common');
        $context = $this->createContext(null, $this->createStub(NickServNotifierInterface::class), $translator);

        self::assertSame('1 segundo', $context->formatDuration(1));
    }

    #[Test]
    public function preservesDurationKeyWhenBothLocalesAreMissing(): void
    {
        $context = $this->createContext(null, $this->createStub(NickServNotifierInterface::class), new Translator('en'));

        self::assertSame('1 duration.unit.second.singular', $context->formatDuration(1));
    }

    #[Test]
    public function presentsHelpAndSemanticRepliesThroughPublicMethods(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'text' => 'Value %value%', 'fragment' => 'Run %command%', 'command' => 'VERIFY',
            'help.footer' => 'footer', 'syntax' => 'HELP', 'presentation.error.syntax' => 'Syntax %syntax%',
            'presentation.error.missing' => 'Missing %nickname%',
            'presentation.error.not_identified' => 'Run %identify%', 'presentation.identify' => 'IDENTIFY',
            'presentation.unknown_command' => 'Unknown %command% %help%',
            'presentation.unknown_help_option' => 'Unknown %command% %option% %help%',
            'identify.native_authentication' => '%command_0% or %command_1%',
            'identify.native_authentication_command_0' => '/NICK A:B', 'identify.native_authentication_command_1' => '/NICK A!B',
            'rescue.unavailable' => 'Use %command_0%', 'rescue.unavailable_command_0' => 'VERIFY',
            'rescue.issued' => 'Token %token_display%. %warning%', 'rescue.issued_warning' => 'Keep secure',
        ], 'en', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $context = $this->createContext(new SenderView('UID', 'Alice', 'i', 'h', 'c', 'ip'), $notifier, $translator, renderer: new IrcRenderer(new FixedStyleProvider(PresentationStyle::default())));
        self::assertTrue($context->hasHelpPresentation());
        self::assertStringContainsString('Value Bob', $context->formatHelp(Component::Text, 'text', ['value' => 'Bob']));
        self::assertStringContainsString('Bob', $context->formatHelpTemplate(Component::Text, '%value%', ['value' => 'Bob']));
        self::assertStringContainsString('Bob', $context->formatHelpRole(Role::Error, 'text', ['value' => 'Bob']));
        self::assertStringContainsString('42', $context->formatHelp(Component::Text, 'text', ['value' => 42]));
        self::assertStringContainsString('─', $context->helpSeparator());
        $context->replyComponentWithLocalizedFragments(Component::Text, 'fragment', [], ['command' => ['command', Component::Command]]);
        $context->replyWithLocalizedFragments('fragment', [], Role::Information, ['command' => ['command', Component::Command]]);
        $context->replyWithLocalizedFragment('text', [], Role::Success, 'command', Component::Command);
        $context->replyEmphasized('text', ['value' => 42, 'other' => new Fragment('existing')], Role::Information, ['value', 'other']);
        $context->replyNativeAuthentication();
        $context->replyRescueUnavailable();
        $context->replyNotIdentified();
        $context->replyUnknownHelpCommand('BAD');
        $context->replyUnknownHelpOption('SET', 'BAD');
        $context->replyMissingNick('error.missing', 'Bob');
        $context->replySyntax('syntax');
        $context->replyComponent(Component::Text, 'fragment', [], ['command' => ['command', Component::Command]]);
        $context->replySeparator();
        $context->replyRawComponent(Component::Emphasis, 'hello');
        $context->replyRescueToken('rescue.issued', 'TOKEN123', Role::Success);
        self::assertCount(15, $messages);
        self::assertStringContainsString('TOKEN123', $messages[14]);
        self::assertStringContainsString('VERIFY', $messages[0]);
        self::assertStringContainsString('42', $messages[3]);
        self::assertStringContainsString('/NICK A!B', $messages[4]);
        self::assertStringContainsString('Bob', $messages[9]);
        self::assertStringContainsString('HELP', $messages[10]);
        self::assertStringContainsString('─', $messages[12]);
        self::assertStringContainsString('hello', $messages[13]);
    }

    #[Test]
    public function unstyledFallbacksResolveNestedFragmentsAndLegacyErrors(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['text' => 'Value %value%', 'fragment' => 'Run %command%', 'command' => 'VERIFY', 'help.footer' => 'footer', 'reply.separator' => 'separator', 'error.not_identified' => 'not identified', 'unknown_command' => 'unknown %command%', 'unknown_help_option' => 'unknown %option%', 'error.syntax' => 'syntax %syntax%', 'syntax' => 'HELP', 'error.missing' => 'missing %nickname%'], 'en', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $context = $this->createContext(new SenderView('UID', 'Alice', 'i', 'h', 'c', 'ip'), $notifier, $translator);
        self::assertFalse($context->hasHelpPresentation());
        self::assertSame('Value Bob', $context->formatHelp(Component::Text, 'text', ['value' => 'Bob']));
        self::assertSame('%value%', $context->formatHelpTemplate(Component::Text, '%value%', ['value' => 'Bob']));
        self::assertSame('Value Bob', $context->formatHelpRole(Role::Error, 'text', ['value' => 'Bob']));
        self::assertSame('footer', $context->helpSeparator());
        $context->reply('text', ['value' => new Fragment('inner %name%', Component::Text, ['%name%' => new Fragment('Bob')])]);
        $context->replyComponentWithLocalizedFragments(Component::Text, 'fragment', [], ['command' => ['command', Component::Command]]);
        $context->replyWithLocalizedFragments('fragment', [], Role::Information, ['command' => ['command', Component::Command]]);
        $context->replyWithLocalizedFragment('text', [], Role::Success, 'command', Component::Command);
        $context->replyComponent(Component::Text, 'fragment', [], ['command' => ['command', Component::Command]]);
        $context->replyNotIdentified();
        $context->replyUnknownHelpCommand('BAD');
        $context->replyUnknownHelpOption('SET', 'BAD');
        $context->replyMissingNick('error.missing', 'Bob');
        $context->replySyntax('syntax');
        $context->replySeparator();
        $context->replyRawComponent(Component::Text, 'plain');
        self::assertSame(['Value inner Bob', 'Run VERIFY', 'Run VERIFY', 'Value VERIFY', 'Run VERIFY', 'not identified', 'unknown BAD', 'unknown BAD', 'missing Bob', 'syntax HELP', 'separator', 'plain'], $messages);
    }

    #[Test]
    public function simpleTranslatorWithoutRendererUsesLegacyReplyPaths(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $context = $this->createContext(new SenderView('UID', 'Alice', 'i', 'h', 'c', 'ip'), $notifier, $translator);
        $context->replyComponentWithLocalizedFragments(Component::Text, 'component', [], ['value' => ['fragment', Component::Command]]);
        $context->replyWithLocalizedFragments('plural', [], Role::Information, ['value' => ['fragment', Component::Command]]);
        $context->replyWithLocalizedFragment('single', [], Role::Information, 'fragment', Component::Command);
        $context->replyComponent(Component::Text, 'row');
        $context->replyIdentifyPending('Alice');
        $context->replyRescueToken('rescue.issued', 'TOKEN', Role::Success);
        self::assertSame(['component', 'plural', 'single', 'row', 'identify.pending', 'rescue.issued'], $messages);
    }

    #[Test]
    public function rescueTokenUsesDefaultStyleWhenTranslatorHasCatalogButRendererIsAbsent(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['rescue.issued' => 'Token %token_display%. %warning%', 'rescue.issued_warning' => 'Store securely'], 'en', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $context = $this->createContext(new SenderView('UID', 'Alice', 'i', 'h', 'c', 'ip'), $notifier, $translator);
        $context->replyRescueToken('rescue.issued', 'TOKEN123', Role::Success);
        self::assertStringContainsString('TOKEN123', $messages[0]);
        self::assertStringContainsString('Store securely', $messages[0]);
        self::assertStringContainsString("\x02\x0303TOKEN123", $messages[0]);
    }

    #[Test]
    public function rejectsNonScalarEmphasisParametersWithoutSending(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $context = $this->createContext(new SenderView('UID', 'Alice', 'i', 'h', 'c', 'ip'), $notifier, new Translator('en'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Emphasized parameters must be scalar or Stringable.');
        $context->replyEmphasized('text', ['value' => ['unexpected']], Role::Error, ['value']);
    }

    #[Test]
    public function rejectsNonScalarPresentationParametersWithoutSending(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $context = $this->createContext(new SenderView('UID', 'Alice', 'i', 'h', 'c', 'ip'), $notifier, new Translator('en'), renderer: new IrcRenderer(new FixedStyleProvider(PresentationStyle::default())));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Presentation parameters must be scalar, Stringable or Fragment.');
        $context->reply('text', ['value' => ['unexpected']], Role::Error);
    }

    /**
     * @param list<string> $args
     */
    private function createContext(
        ?SenderView $sender,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        array $args = [],
        ?NickServCommandRegistry $registry = null,
        ?PendingVerificationRegistry $pendingVerification = null,
        ?RecoveryTokenRegistry $recoveryToken = null,
        ?RegisteredNick $senderAccount = null,
        string $language = 'en',
        ?IrcRenderer $renderer = null,
    ): NickServContext {
        $serviceNicks = $this->createServiceNicks('NickServ');

        return new NickServContext(
            $sender,
            $senderAccount,
            'INFO',
            $args,
            $notifier,
            $translator,
            $language,
            'Europe/Madrid',
            'NOTICE',
            $registry ?? new NickServCommandRegistry([]),
            $pendingVerification ?? new PendingVerificationRegistry(),
            $recoveryToken ?? new RecoveryTokenRegistry(),
            $serviceNicks,
            $renderer,
        );
    }

    private function createServiceNicks(string $botName): ServiceNicknameRegistry
    {
        $nickservProvider = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $chanservProvider = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $memoservProvider = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $operservProvider = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$nickservProvider, $chanservProvider, $memoservProvider, $operservProvider]);
    }
}
