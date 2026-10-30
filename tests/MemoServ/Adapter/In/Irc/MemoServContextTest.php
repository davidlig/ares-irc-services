<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
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

use function array_slice;
use function implode;
use function is_string;
use function strtr;

#[CoversClass(MemoServContext::class)]
final class MemoServContextTest extends TestCase
{
    #[Test]
    public function presentationMethodsSupportStyledAndLegacyContexts(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$sent): void { $sent[] = $message; });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params): string {
            $templates = ['presentation.identify' => 'IDENTIFY', 'presentation.error.not_identified' => 'Use %identify% for %nickname% %operation%', 'row' => '%value%', 'reply.separator' => 'legacy separator'];

            return strtr($templates[$id] ?? $id, $params);
        });
        $style = new PresentationStyle(markers: ['error' => '!'], components: ['command' => new Appearance(prefix: '[', suffix: ']'), 'emphasis' => new Appearance(prefix: '<', suffix: '>'), 'row' => new Appearance(prefix: 'R:')]);
        $renderer = new IrcRenderer(new FixedStyleProvider($style));
        $make = fn (?IrcRenderer $selected): MemoServContext => new MemoServContext(new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip'), null, 'HELP', [], $notifier, $translator, 'en', 'UTC', 'NOTICE', new MemoServCommandRegistry([]), $this->createServiceNicks(), $selected);
        $styled = $make($renderer);
        $styled->replyError('error.not_identified', ['nick' => 'Bob', 'operation' => 'SEND']);
        $styled->replyComponent(Component::Row, 'row', ['value' => $styled->commandFragment('SEND')]);
        self::assertSame("! Use [IDENTIFY] for <Bob> [SEND]\nR:[SEND]", implode("\n", $sent));
        self::assertInstanceOf(Fragment::class, $styled->emphasize('Bob'));
        self::assertSame('R:hello', $styled->renderComponent(Component::Row, 'hello'));
        self::assertSame($renderer->separator(3), $styled->separator(3));
        $legacy = $make(null);
        $legacy->replyError('legacy error');
        $legacy->replyComponent(Component::Row, 'row', ['value' => 'plain']);
        $legacy->replySeparator();
        self::assertSame(['legacy error', 'plain', 'legacy separator'], array_slice($sent, 2));
        self::assertSame('SEND', $legacy->commandFragment('SEND'));
        self::assertSame('Bob', $legacy->emphasize('Bob'));
        self::assertNotSame('', $legacy->renderComponent(Component::Row, 'hello'));
        self::assertNotSame('', $legacy->separator(3));
        $beforeInvalid = implode("\n", $sent);
        try {
            $styled->replyComponent(Component::Row, 'row', ['value' => ['invalid']]);
            self::fail('A non-text presentation parameter must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('IRC presentation parameters must be text or fragments.', $exception->getMessage());
        }
        self::assertSame($beforeInvalid, implode("\n", $sent));
    }

    #[Test]
    public function exposesIndependentSenderIdentityAndIrcOperatorFacts(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = new MemoAccountView(42, 'OperNick', 'en');
        $context = new MemoServContext(
            $sender,
            $account,
            'HELP',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );

        self::assertSame(42, $context->getSenderAccountId());
        self::assertSame('OperNick', $context->getSenderNickname());
        self::assertTrue($context->isSenderIdentified());
        self::assertTrue($context->isSenderIrcOperator());
    }

    #[Test]
    public function replyTranslatesAndSendsWhenSenderIsSet(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg, string $type) use (&$sent): void {
            $sent[] = ['uid' => $uid, 'msg' => $msg, 'type' => $type];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params, string $domain, ?string $locale): string {
            $name = $params['%name%'] ?? null;

            return $id . '|' . (is_string($name) ? $name : '');
        });
        $registry = new MemoServCommandRegistry([]);
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip');
        $context = new MemoServContext(
            $sender,
            null,
            'SEND',
            [],
            $notifier,
            $translator,
            'en',
            'Europe/Madrid',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        $context->reply('memo.sent', ['name' => 'Bob']);

        self::assertCount(1, $sent);
        self::assertSame('UID1', $sent[0]['uid']);
        self::assertSame('memo.sent|Bob', $sent[0]['msg']);
        self::assertSame('NOTICE', $sent[0]['type']);
    }

    #[Test]
    public function replyRendersAnExplicitSemanticRoleWithoutInterpolatingUserTextTwice(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$sent): void {
            $sent[] = [$uid, $message, $type];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('To %name%: %message%');
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['success' => '+'])));
        $context = new MemoServContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'SEND',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'PRIVMSG',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
            $renderer,
        );

        $context->reply('send.sent_nick', ['name' => 'Bob', 'message' => '%name%'], Role::Success);

        self::assertSame([['UID1', '+ To Bob: %name%', 'PRIVMSG']], $sent);
    }

    #[Test]
    public function rendersMigratedCommonErrorsWithAnExplicitErrorRole(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$sent): void { $sent[] = $message; });
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['presentation' => ['error.channel_not_registered' => 'Channel %channel% missing']], 'en', 'common');
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['error' => '!'])));
        $context = new MemoServContext(
            new SenderView('UID', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'SEND',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
            $renderer
        );

        $context->replyError('error.channel_not_registered', ['channel' => '#chan']);

        self::assertSame(['! Channel #chan missing'], $sent);
    }

    #[Test]
    public function replyDoesNotSendWhenSenderIsNull(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function () use (&$sent): void {
            $sent[] = true;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            null,
            null,
            'SEND',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        $context->reply('some.key');

        self::assertCount(0, $sent);
    }

    #[Test]
    public function replyRawSendsMessageWhenSenderIsSet(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$sent): void {
            $sent[] = ['uid' => $uid, 'msg' => $msg];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new MemoServCommandRegistry([]);
        $sender = new SenderView('UID2', 'User', 'i', 'h', 'c', 'ip');
        $context = new MemoServContext(
            $sender,
            null,
            'READ',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        $context->replyRaw('Raw line');

        self::assertCount(1, $sent);
        self::assertSame('UID2', $sent[0]['uid']);
        self::assertSame('Raw line', $sent[0]['msg']);
    }

    #[Test]
    public function replyRawDoesNotSendWhenSenderIsNull(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function () use (&$sent): void {
            $sent[] = true;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            null,
            null,
            'LIST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        $context->replyRaw('Anything');

        self::assertCount(0, $sent);
    }

    #[Test]
    public function gettersReturnInjectedValues(): void
    {
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            null,
            null,
            'HELP',
            ['arg1'],
            $notifier,
            $translator,
            'es',
            'Europe/Madrid',
            'PRIVMSG',
            $registry,
            $this->createServiceNicks(),
        );

        self::assertSame($notifier, $context->getNotifier());
        self::assertSame('es', $context->getLanguage());
        self::assertSame('Europe/Madrid', $context->getTimezone());
        self::assertSame($registry, $context->getRegistry());
        self::assertSame('HELP', $context->command);
        self::assertSame(['arg1'], $context->args);
    }

    #[Test]
    public function formatDateReturnsDashWhenDateIsNull(): void
    {
        $context = $this->createMinimalContext();

        self::assertSame('—', $context->formatDate(null));
    }

    #[Test]
    public function formatDateFormatsWithContextTimezone(): void
    {
        $context = $this->createMinimalContext('UTC');
        $date = new DateTimeImmutable('2025-03-14 12:00:00', new DateTimeZone('UTC'));

        $formatted = $context->formatDate($date);

        self::assertSame('14/03/2025 12:00 UTC', $formatted);
    }

    #[Test]
    public function transReturnsTranslationWithWrappedParams(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['memo.from' => 'memo.from:%nick%'], 'en', 'memoserv');
        $context = new MemoServContext(
            null,
            null,
            'SEND',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );

        $result = $context->trans('memo.from', ['nick' => 'Alice']);

        self::assertSame('memo.from:Alice', $result);
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
    public function transPrefersCommonTranslationWhenAvailable(string $language): void
    {
        $translator = new Translator($language);
        $translator->addLoader('array', new ArrayLoader());
        $translator->setFallbackLocales(['en']);
        $translator->addResource('array', ['error.syntax' => 'Service translation'], 'en', 'memoserv');
        $translator->addResource('array', ['error.syntax' => 'Shared translation'], 'en', 'common');
        $translator->addResource('array', ['service.only' => 'Service-only message'], 'en', 'memoserv');
        $context = new MemoServContext(
            null,
            null,
            'SEND',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $translator,
            $language,
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );

        self::assertSame('Shared translation', $context->trans('error.syntax'));
        self::assertSame('Service-only message', $context->trans('service.only'));
        self::assertSame('missing.key', $context->trans('missing.key'));
    }

    #[Test]
    public function getSenderReturnsSenderView(): void
    {
        $sender = new SenderView('UID123', 'TestNick', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestNick', 'en');
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $serviceNicks = $this->createServiceNicks();

        $context = new MemoServContext(
            $sender,
            $account,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $serviceNicks,
        );

        self::assertSame($sender, $context->getSender());
    }

    #[Test]
    public function getSenderAccountReturnsAccount(): void
    {
        $sender = new SenderView('UID123', 'TestNick', 'ident', 'host', 'cloak', 'ip');
        $account = new MemoAccountView(1, 'TestNick', 'en');
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $serviceNicks = $this->createServiceNicks();

        $context = new MemoServContext(
            $sender,
            $account,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $serviceNicks,
        );

        self::assertSame($account, $context->getSenderAccount());
        self::assertSame(1, $context->getSenderAccountId());
    }

    #[Test]
    public function getSenderReturnsNullWhenSenderIsNull(): void
    {
        $context = $this->createMinimalContext();

        self::assertNull($context->getSender());
        self::assertNull($context->getSenderAccount());
        self::assertNull($context->getSenderAccountId());
    }

    private function createServiceNicks(): ServiceNicknameRegistry
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

        return new ServiceNicknameRegistry([
            $nickservProvider,
            $chanservProvider,
            $memoservProvider,
            $operservProvider,
        ]);
    }

    private function createMinimalContext(string $timezone = 'UTC'): MemoServContext
    {
        return new MemoServContext(
            null,
            null,
            'LIST',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            $timezone,
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );
    }
}
