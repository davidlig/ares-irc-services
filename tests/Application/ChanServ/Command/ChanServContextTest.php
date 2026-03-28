<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChanServContext::class)]
final class ChanServContextTest extends TestCase
{
    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $nickservProvider = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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

    private function createContext(
        ?SenderView $sender,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        array $args = ['#test', 'INFO'],
        ?ChannelLookupPort $channelLookup = null,
        ?ChannelModeSupportInterface $modeSupport = null,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            null,
            'INFO',
            $args,
            $notifier,
            $translator,
            'en',
            'Europe/Madrid',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            $modeSupport ?? new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
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
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params, string $domain, ?string $locale): string => $id . '|' . ($params['%name%'] ?? ''));
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

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with(
                'test.key',
                $this->arrayHasKey('%param%'),
                'chanserv',
                'en'
            )
            ->willReturn('Translated');

        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        $result = $context->trans('test.key', ['param' => 'value']);

        self::assertSame('Translated', $result);
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
        $account = $this->createStub(RegisteredNick::class);
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
        $account = $this->createStub(RegisteredNick::class);
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
    }
}
