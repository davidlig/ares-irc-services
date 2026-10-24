<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NickServContext::class)]
final class NickServContextTest extends TestCase
{
    /**
     * @param string[] $args
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
        );
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
}
