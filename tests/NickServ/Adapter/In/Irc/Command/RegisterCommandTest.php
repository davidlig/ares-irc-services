<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\RegisterCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\UseCase\Register\RegisterNick;
use App\NickServ\Application\UseCase\Register\RegisterNickHandlerInterface;
use App\NickServ\Application\UseCase\Register\RegisterNickResult;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;
use function str_starts_with;

#[CoversClass(RegisterCommand::class)]
final class RegisterCommandTest extends TestCase
{
    #[Test]
    public function exposesRegisterMetadata(): void
    {
        $command = new RegisterCommand($this->createStub(RegisterNickHandlerInterface::class), verificationTokenTtlSeconds: 3600);

        self::assertSame('REGISTER', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('register.syntax', $command->getSyntaxKey());
        self::assertSame('register.help', $command->getHelpKey());
        self::assertSame('register.example', $command->getExampleKey());
        self::assertSame(1, $command->getOrder());
        self::assertSame('register.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertSame(['duration_seconds' => 3600], $command->getHelpParams());
    }

    #[Test]
    public function exposesConfiguredSecondsForHelp(): void
    {
        $command = new RegisterCommand($this->createStub(RegisterNickHandlerInterface::class), verificationTokenTtlSeconds: 300);

        self::assertSame(['duration_seconds' => 300], $command->getHelpParams());
    }

    #[Test]
    public function formatsDurationForCurrentLocaleAndFallsBackToSpanishWhenKeysAreMissing(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/common.es.yaml', 'es', 'common');
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');

        $messages = [];
        self::assertSame('1 hora', $this->context(null, $messages, translator: $translator, language: 'en')->formatDuration(3600));
    }

    /** @param array{uid: string, nick: string, ident: string, hostname: string, cloakedHost: string, ipBase64: string} $senderData */
    #[Test]
    #[DataProvider('clientKeys')]
    public function mapsIrcInputToTypedUseCaseInput(array $senderData, string $expectedClientKey): void
    {
        $sender = new SenderView(...$senderData);
        $handler = $this->createMock(RegisterNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (RegisterNick $input): bool => 'CurrentNick' === $input->nickname
                && 'plain-secret' === $input->password
                && 'user@example.com' === $input->email
                && 'es' === $input->language
                && $expectedClientKey === $input->clientKey,
        ))->willReturn(RegisterNickResult::verificationRequired('user@example.com'));

        $messages = [];
        $command = new RegisterCommand($handler, verificationTokenTtlSeconds: 3600);
        $command->execute($this->context($sender, $messages));

        self::assertSame(['register.pending'], $messages);
    }

    /**
     * @return iterable<string, array{array{uid: string, nick: string, ident: string, hostname: string, cloakedHost: string, ipBase64: string}, string}>
     */
    public static function clientKeys(): iterable
    {
        yield 'IP has priority' => [[
            'uid' => 'UID1', 'nick' => 'CurrentNick', 'ident' => 'ident', 'hostname' => 'host', 'cloakedHost' => 'cloak', 'ipBase64' => 'encoded-ip',
        ], 'ip:encoded-ip'];
        yield 'cloak is the first fallback' => [[
            'uid' => 'UID1', 'nick' => 'CurrentNick', 'ident' => 'ident', 'hostname' => 'host', 'cloakedHost' => 'cloak', 'ipBase64' => '*',
        ], 'cloak:cloak'];
        yield 'hostname follows cloak' => [[
            'uid' => 'UID1', 'nick' => 'CurrentNick', 'ident' => 'ident', 'hostname' => 'host', 'cloakedHost' => '', 'ipBase64' => '',
        ], 'host:host'];
        yield 'UID is the final fallback' => [[
            'uid' => 'UID1', 'nick' => 'CurrentNick', 'ident' => 'ident', 'hostname' => '', 'cloakedHost' => '', 'ipBase64' => '*',
        ], 'uid:UID1'];
    }

    /** @param array<string, mixed> $expectedParams */
    #[Test]
    #[DataProvider('presentations')]
    public function presentsSemanticResults(RegisterNickResult $result, string $expectedKey, array $expectedParams): void
    {
        $handler = $this->createStub(RegisterNickHandlerInterface::class);
        $handler->method('handle')->willReturn($result);
        /** @var list<array{0: string, 1: array<string, mixed>}> $calls */
        $calls = [];

        $command = new RegisterCommand($handler, verificationTokenTtlSeconds: 300);
        $command->execute($this->context(
            new SenderView('UID1', 'CurrentNick', 'ident', 'host', 'cloak', 'ip'),
            $calls,
            captureParams: true,
        ));

        self::assertCount(1, $calls);
        $call = $calls[0];
        self::assertIsArray($call);
        self::assertSame($expectedKey, $call[0]);
        $params = $call[1];
        self::assertIsArray($params);
        foreach ($expectedParams as $name => $value) {
            self::assertSame($value, $params[$name] ?? null);
        }
    }

    /** @return iterable<string, array{RegisterNickResult, string, array<string, mixed>}> */
    public static function presentations(): iterable
    {
        yield 'token verification' => [RegisterNickResult::tokenVerificationRequired('uuid'), 'rescue.issued', ['%token%' => 'uuid']];
        yield 'token unavailable' => [RegisterNickResult::temporarilyUnavailable(), 'register.token_unavailable', []];
        yield 'verification required' => [RegisterNickResult::verificationRequired('a@b.test'), 'register.pending', ['%email%' => 'a@b.test', '%duration%' => '5 minutos']];
        yield 'throttled rounds up minutes' => [RegisterNickResult::throttled(61), 'register.throttled', ['%minutes%' => '2']];
        yield 'guest prefix' => [RegisterNickResult::guestPrefixForbidden('Guest-'), 'register.guest_prefix_forbidden', ['%prefix%' => 'Guest-']];
        yield 'blocked domain' => [RegisterNickResult::blockedEmailDomain(), 'error.email_domain_blocked', []];
        yield 'invalid email' => [RegisterNickResult::invalidEmail(), 'register.invalid_email', []];
        yield 'email used' => [RegisterNickResult::emailAlreadyUsed('a@b.test'), 'register.email_already_used', ['%email%' => 'a@b.test']];
        yield 'already pending' => [RegisterNickResult::alreadyPending('Nick'), 'register.already_pending', ['%nickname%' => 'Nick']];
        yield 'forbidden' => [RegisterNickResult::forbidden('Nick'), 'register.forbidden', ['%nickname%' => 'Nick']];
        yield 'pending deletion' => [RegisterNickResult::pendingDeletion('Nick'), 'register.pending_deletion', ['%nickname%' => 'Nick']];
        yield 'already registered' => [RegisterNickResult::alreadyRegistered('Nick'), 'register.already_registered', ['%nickname%' => 'Nick']];
        yield 'mail failed' => [RegisterNickResult::mailDeliveryFailed(), 'error.mail_failed', []];
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = $this->createMock(RegisterNickHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $messages = [];

        new RegisterCommand($handler, verificationTokenTtlSeconds: 3600)->execute($this->context(null, $messages));

        self::assertSame([], $messages);
    }

    #[Test]
    public function mapsAbsentOptionalEmailWithoutAnUndefinedArgument(): void
    {
        $handler = $this->createMock(RegisterNickHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(static fn (RegisterNick $input): bool => null === $input->email && 'plain-secret' === $input->password))->willReturn(RegisterNickResult::tokenVerificationRequired('uuid'));
        $messages = [];
        new RegisterCommand($handler, verificationTokenTtlSeconds: 3600)->execute($this->context(new SenderView('UID1', 'Alice', 'i', 'h', 'c', 'ip'), $messages, args: ['plain-secret']));
        self::assertSame(['rescue.issued'], $messages);
    }

    #[Test]
    public function registerErrorsUseStyledFragmentsWithSpanishCatalog(): void
    {
        $handler = $this->createStub(RegisterNickHandlerInterface::class);
        $handler->method('handle')->willReturnOnConsecutiveCalls(RegisterNickResult::alreadyRegistered('Alice'), RegisterNickResult::invalidEmail(), RegisterNickResult::alreadyPending('Alice'));
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'Alice', 'i', 'h', 'c', 'ip'), null, 'REGISTER', ['password'], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), new ServiceNicknameRegistry([$this->nicknameProvider()]), $renderer);
        $command = new RegisterCommand($handler, 3600);
        $command->execute($context);
        $command->execute($context);
        $command->execute($context);
        self::assertSame(["\x0304✗\x03\x0F El apodo \x02Alice\x03\x0F ya está registrado.", "\x0304✗\x03\x0F La dirección de correo electrónico no es válida.", "\x0310ℹ\x03\x0F El registro de \x02Alice\x03\x0F está pendiente. Usa \x02\x0303VERIFY <token>\x03\x0F. Si el token se envió por correo y hay una dirección válida asociada, puedes solicitar otro con \x02\x0303RESEND\x03\x0F."], $messages);
    }

    #[Test]
    public function pendingRegistrationRendersNestedCommandsAndContinuationRows(): void
    {
        $handler = $this->createStub(RegisterNickHandlerInterface::class);
        $handler->method('handle')->willReturn(RegisterNickResult::verificationRequired('a@example.test'));
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/common.es.yaml', 'es', 'common');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'Alice', 'i', 'h', 'c', 'ip'), null, 'REGISTER', ['password'], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), new ServiceNicknameRegistry([$this->nicknameProvider()]), $renderer);
        new RegisterCommand($handler, 3600)->execute($context);
        self::assertSame(["\x0310ℹ\x03\x0F Se ha enviado un token de verificación a \x02a@example.test\x03\x0F.\n  Ejecuta /msg CustomNick \x02\x0303VERIFY <token>\x03\x0F para completar el registro.\n  ¿No te llega? Revisa spam o usa /msg CustomNick \x02\x0303RESEND\x03\x0F\n  El token expirará en \x021 hora\x03\x0F."], $messages);
    }

    #[Test]
    public function pendingRegistrationWithoutRendererDoesNotLeakTemplateSlots(): void
    {
        $handler = $this->createStub(RegisterNickHandlerInterface::class);
        $handler->method('handle')->willReturn(RegisterNickResult::verificationRequired('a@example.test'));
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/common.es.yaml', 'es', 'common');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $context = new NickServContext(new SenderView('UID1', 'Alice', 'i', 'h', 'c', 'ip'), null, 'REGISTER', ['password'], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), new ServiceNicknameRegistry([$this->nicknameProvider()]));
        new RegisterCommand($handler, 3600)->execute($context);
        self::assertStringNotContainsString('%email%', $messages[0]);
        self::assertStringContainsString('a@example.test', $messages[0]);
        self::assertStringContainsString('/msg CustomNick VERIFY <token>', $messages[0]);
    }

    /** @param list<mixed> $messages
     * @param list<string> $args
     */
    private function context(
        ?SenderView $sender,
        array &$messages,
        bool $captureParams = false,
        array $args = ['plain-secret', 'user@example.com'],
        ?TranslatorInterface $translator = null,
        string $language = 'es',
    ): NickServContext {
        if (null === $translator) {
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(static function (string $key, array $params) use (&$messages, $captureParams): string {
                if (str_starts_with($key, 'register.pending_line_')) {
                    return $key;
                }
                $unitTranslations = [
                    'duration.unit.hour.singular' => 'hora',
                    'duration.unit.minute.plural' => 'minutos',
                ];
                if (isset($unitTranslations[$key])) {
                    return $unitTranslations[$key];
                }

                $messages[] = $captureParams ? [$key, $params] : $key;

                return $key;
            });
        }
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');

        return new NickServContext(
            $sender,
            null,
            'REGISTER',
            $args,
            $notifier,
            $translator,
            $language,
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([$this->nicknameProvider()]),
        );
    }

    private function nicknameProvider(): ServiceNicknameProviderInterface
    {
        return new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
    }
}
