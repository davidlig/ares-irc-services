<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServDetailPresenter;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Application\Model\ChanAccountView;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_string;

#[CoversClass(ChanServContext::class)]
#[CoversClass(ChanServDetailPresenter::class)]
final class ChanServContextTest extends TestCase
{
    #[Test]
    public function exposesIndependentSenderIdentityAndIrcOperatorFacts(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = new ChanAccountView(42, 'OperNick', 'en');
        $context = $this->createContext(
            $sender,
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            senderAccount: $account,
        );

        self::assertSame(42, $context->getSenderAccountId());
        self::assertSame('OperNick', $context->getSenderNickname());
        self::assertTrue($context->isSenderIdentified());
        self::assertTrue($context->isSenderIrcOperator());
    }

    #[Test]
    public function getChannelNameArgReturnsChannelWhenStartsWithHash(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            ['#mychan', 'INFO'],
        );

        self::assertSame('#mychan', $context->getChannelNameArg(0));
    }

    #[Test]
    public function getChannelNameArgReturnsNullWhenNotChannelLike(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            ['User123', 'INFO'],
        );

        self::assertNull($context->getChannelNameArg(0));
    }

    #[Test]
    public function getChannelNameArgReturnsNullWhenIndexOutOfBounds(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            ['#test'],
        );

        self::assertNull($context->getChannelNameArg(1));
    }

    #[Test]
    public function getChannelViewReturnsChannelWhenFound(): void
    {
        $channelView = new ChannelView('#test', '+nt', 'Topic', 0, [], 0, []);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            ['#test'],
            $channelLookup,
        );

        self::assertSame($channelView, $context->getChannelView('#test'));
    }

    #[Test]
    public function getChannelViewReturnsNullWhenNotFound(): void
    {
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            ['#test'],
            $channelLookup,
        );

        self::assertNull($context->getChannelView('#nonexistent'));
    }

    #[Test]
    public function replyTranslatesAndSendsWhenSenderIsSet(): void
    {
        $sent = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg, string $type) use (&$sent): void {
            $sent[] = ['uid' => $uid, 'msg' => $msg, 'type' => $type];
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params, string $domain, ?string $locale): string => $id . '|' . (is_string($params['%name%'] ?? null) ? $params['%name%'] : ''));
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            ['#test'],
        );

        $context->reply('test.key', ['name' => 'Bob']);

        self::assertCount(1, $sent);
        self::assertSame('UID1', $sent[0]['uid']);
        self::assertSame('test.key|Bob', $sent[0]['msg']);
        self::assertSame('NOTICE', $sent[0]['type']);
    }

    #[Test]
    public function replyDoesNotSendWhenSenderIsNull(): void
    {
        $sent = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function () use (&$sent): void {
            $sent[] = true;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');
        $context = $this->createContext(
            null,
            $notifier,
            $translator,
        );

        $context->reply('test.key');

        self::assertEmpty($sent);
    }

    #[Test]
    public function replyRawSendsMessageDirectly(): void
    {
        $sent = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg, string $type) use (&$sent): void {
            $sent[] = ['uid' => $uid, 'msg' => $msg, 'type' => $type];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        $context->replyRaw('Raw message');

        self::assertCount(1, $sent);
        self::assertSame('Raw message', $sent[0]['msg']);
    }

    #[Test]
    public function replyRawDoesNotSendWhenSenderIsNull(): void
    {
        $sent = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function () use (&$sent): void {
            $sent[] = true;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            null,
            $notifier,
            $translator,
        );

        $context->replyRaw('Raw message');

        self::assertEmpty($sent);
    }

    #[Test]
    public function getNotifierReturnsCorrectValue(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        self::assertSame($notifier, $context->getNotifier());
    }

    #[Test]
    public function getLanguageReturnsCorrectValue(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        self::assertSame('en', $context->getLanguage());
    }

    #[Test]
    public function getTimezoneReturnsCorrectValue(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        self::assertSame('Europe/Madrid', $context->getTimezone());
    }

    #[Test]
    public function formatDateReturnsFormattedDateInTimezone(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        $date = new DateTime('2024-01-15 10:30:00', new DateTimeZone('UTC'));
        $result = $context->formatDate($date);

        self::assertStringContainsString('15/01/2024', $result);
    }

    #[Test]
    public function formatDateReturnsEmDashWhenDateIsNull(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        self::assertSame('—', $context->formatDate(null));
    }

    #[Test]
    public function getRegistryReturnsCorrectValue(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new ChanServCommandRegistry([]);
        $context = new ChanServContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame($registry, $context->getRegistry());
    }

    #[Test]
    public function transTranslatesWithCorrectParameters(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');

        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['test.key' => 'Translated %param%'], 'en', 'chanserv');

        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        $result = $context->trans('test.key', ['param' => 'value']);

        self::assertSame('Translated value', $result);
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
        $translator->addResource('array', ['error.syntax' => 'Service translation'], 'en', 'chanserv');
        $translator->addResource('array', ['error.syntax' => 'Shared translation'], 'en', 'common');
        $translator->addResource('array', ['service.only' => 'Service-only message'], 'en', 'chanserv');
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $this->createStub(ChanServNotifierInterface::class),
            $translator,
            language: $language,
        );

        self::assertSame('Shared translation', $context->trans('error.syntax'));
        self::assertSame('Service-only message', $context->trans('service.only'));
        self::assertSame('missing.key', $context->trans('missing.key'));
    }

    #[Test]
    public function rendersLocalizedComponentsWithStructuredFragments(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['help.line' => 'Usa %command%'], 'es', 'chanserv');
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $this->createStub(ChanServNotifierInterface::class),
            $translator,
            language: 'es',
        );

        self::assertSame("Usa \x02\x0303HELP\x03\x0F", $context->renderComponent(
            Component::Text,
            'help.line',
            ['command' => new Fragment('HELP', Component::Command)],
        ));
        self::assertSame("  /msg \x02\x0303HELP\x03\x0F", $context->renderTextComponent(
            Component::Example,
            '/msg %command%',
            ['command' => new Fragment('HELP', Component::Command)],
        ));
        self::assertSame("\x0314───\x03\x0F", $context->renderSeparator(3));
    }

    #[Test]
    public function sendsRoleReplyWithScalarParametersAndSelectedStyle(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['notice' => '%name% has %count% entries, ratio %ratio%, enabled %enabled%, missing %missing%.'], 'en', 'chanserv');
        $sent = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message, string $type) use (&$sent): void {
            $sent[] = [$uid, $message, $type];
        });
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip');
        $params = ['name' => 'Nick', 'count' => 3, 'ratio' => 1.5, 'enabled' => true, 'missing' => null];
        $default = $this->createContext($sender, $notifier, $translator);
        $default->reply('notice', $params, Role::Success);
        self::assertSame([['UID1', "\x0303✔\x03\x0F Nick has 3 entries, ratio 1.5, enabled 1, missing .", 'NOTICE']], $sent);
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: [Role::Success->value => 'OK'])));
        $custom = $this->createContext($sender, $notifier, $translator, renderer: $renderer);
        self::assertSame('OK Nick has 3 entries, ratio 1.5, enabled 1, missing .', $custom->renderReply(Role::Success, 'notice', $params));
    }

    #[Test]
    public function rejectsNestedPresentationParametersWithoutSending(): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['notice' => 'Channel %channel%'], 'en', 'chanserv');
        $context = $this->createContext(new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'), $notifier, $translator);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IRC presentation parameters must be scalar or fragments.');
        $context->reply('notice', ['channel' => ['#channel']], Role::Information);
    }

    #[Test]
    public function detailPresenterRendersLocalizedStateWithItsSemanticStyle(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'status.pending_label' => 'Status',
            'status.pending' => '%label%%padding%: %state%',
            'status.pending_value' => 'Approval pending',
        ], 'en', 'chanserv');
        $context = $this->createContext(null, $this->createStub(ChanServNotifierInterface::class), $translator);
        self::assertSame("  \x0314Status\x03\x0F                  : \x0307Approval pending\x03\x0F", new ChanServDetailPresenter()->row($context, 'status.pending', [], Component::Status));
    }

    #[Test]
    public function transWrapsParametersWithPercentSigns(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with(
                'test.key',
                $this->arrayHasKey('%param%')
            )
            ->willReturn('Translated');

        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        $context->trans('test.key', ['%param%' => 'value']);
    }

    #[Test]
    public function transIsIdempotentForAlreadyWrappedKeys(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with(
                'test.key',
                $this->arrayHasKey('%param%')
            )
            ->willReturn('Translated');

        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        $context->trans('test.key', ['%param%' => 'value']);
    }

    #[Test]
    public function getChannelLookupReturnsInjectedPort(): void
    {
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $context = new ChanServContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
            ['#test'],
            $notifier,
            $translator,
            'en',
            'Europe/Madrid',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame($channelLookup, $context->getChannelLookup());
    }

    #[Test]
    public function getChannelModeSupportReturnsInjectedSupport(): void
    {
        $modeSupport = new NullChannelModeSupport();
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $context = new ChanServContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
            ['#test'],
            $notifier,
            $translator,
            'en',
            'Europe/Madrid',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $modeSupport,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        self::assertSame($modeSupport, $context->getChannelModeSupport());
    }

    #[Test]
    public function getUserLookupReturnsInjectedPort(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $context = new ChanServContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
            ['#test'],
            $notifier,
            $translator,
            'en',
            'Europe/Madrid',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $userLookup,
            $this->createServiceNicks(),
        );

        self::assertSame($userLookup, $context->getUserLookup());
    }

    #[Test]
    public function getSenderReturnsSenderView(): void
    {
        $sender = new SenderView('UID123', 'TestNick', 'ident', 'host', 'cloak', 'ip');
        $account = new ChanAccountView(1, 'TestNick', 'en');
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new ChanServCommandRegistry([]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $serviceNicks = $this->createServiceNicks();

        $context = new ChanServContext(
            $sender,
            $account,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $channelLookup,
            $modeSupport,
            $userLookup,
            $serviceNicks,
        );

        self::assertSame($sender, $context->getSender());
    }

    #[Test]
    public function getSenderAccountReturnsAccount(): void
    {
        $sender = new SenderView('UID123', 'TestNick', 'ident', 'host', 'cloak', 'ip');
        $account = new ChanAccountView(1, 'TestNick', 'en');
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new ChanServCommandRegistry([]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $serviceNicks = $this->createServiceNicks();

        $context = new ChanServContext(
            $sender,
            $account,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $channelLookup,
            $modeSupport,
            $userLookup,
            $serviceNicks,
        );

        self::assertSame($account, $context->getSenderAccount());
    }

    #[Test]
    public function getSenderReturnsNullWhenSenderIsNull(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new ChanServCommandRegistry([]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $serviceNicks = $this->createServiceNicks();

        $context = new ChanServContext(
            null,
            null,
            'TEST',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $channelLookup,
            $modeSupport,
            $userLookup,
            $serviceNicks,
        );

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

    /**
     * @param string[] $args
     */
    private function createContext(
        ?SenderView $sender,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        array $args = ['#test', 'INFO'],
        ?ChannelLookupPort $channelLookup = null,
        ?ChannelModeSupportInterface $modeSupport = null,
        ?ChanAccountView $senderAccount = null,
        string $language = 'en',
        ?IrcRenderer $renderer = null,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'INFO',
            $args,
            $notifier,
            $translator,
            $language,
            'Europe/Madrid',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            $modeSupport ?? new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            renderer: $renderer,
        );
    }
}
