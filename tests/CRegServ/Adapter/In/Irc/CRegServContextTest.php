<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\In\Irc;

use App\CRegServ\Adapter\In\Irc\CRegServCommandRegistry;
use App\CRegServ\Adapter\In\Irc\CRegServContext;
use App\CRegServ\Adapter\In\Irc\CRegServNotifierInterface;
use App\CRegServ\Application\Model\CRegServAccountView;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(CRegServContext::class)]
final class CRegServContextTest extends TestCase
{
    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $nickservProvider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'nickserv';
            }

            public function getNickname(): string
            {
                return 'NickServ';
            }
        };
        $cregservProvider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'cregserv';
            }

            public function getNickname(): string
            {
                return 'CReG';
            }
        };

        return new ServiceNicknameRegistry([$nickservProvider, $cregservProvider]);
    }

    /**
     * @param string[] $args
     */
    private function createContext(
        ?SenderView $sender,
        CRegServNotifierInterface $notifier,
        TranslatorInterface $translator,
        array $args = ['#test'],
        ?CRegServAccountView $senderAccount = null,
        string $command = 'ESTADO',
    ): CRegServContext {
        return new CRegServContext(
            $sender,
            $senderAccount,
            $command,
            $args,
            $notifier,
            $translator,
            'en',
            'Europe/Madrid',
            'NOTICE',
            new CRegServCommandRegistry([]),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function exposesIndependentSenderIdentityAndIrcOperatorFacts(): void
    {
        $sender = new SenderView('UID1', 'OperNick', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = new CRegServAccountView(42, 'OperNick', 'en');
        $context = $this->createContext(
            $sender,
            $this->createStub(CRegServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            senderAccount: $account,
        );

        self::assertSame($sender, $context->getSender());
        self::assertSame($account, $context->getSenderAccount());
        self::assertSame(42, $context->getSenderAccountId());
        self::assertSame('OperNick', $context->getSenderNickname());
        self::assertTrue($context->isSenderIdentified());
        self::assertTrue($context->isSenderIrcOperator());
    }

    #[Test]
    public function exposesNoIdentityFactsWhenTheSenderIsNull(): void
    {
        $context = $this->createContext(
            null,
            $this->createStub(CRegServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
        );

        self::assertNull($context->getSender());
        self::assertNull($context->getSenderAccount());
        self::assertNull($context->getSenderAccountId());
        self::assertNull($context->getSenderNickname());
        self::assertFalse($context->isSenderIdentified());
        self::assertFalse($context->isSenderIrcOperator());
    }

    #[Test]
    public function replyTranslatesAndSendsWhenSenderIsSet(): void
    {
        $sent = [];
        $notifier = $this->createNotifier($sent);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'estado.registered',
                $this->callback(static fn (array $params): bool => 'CReG' === ($params['%bot%'] ?? null) && '#test' === ($params['%channel%'] ?? null)),
                'cregserv',
                'en',
            )
            ->willReturn('Channel #test is registered.');
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        $context->reply('estado.registered', ['channel' => '#test']);

        self::assertCount(1, $sent);
        self::assertSame('UID1', $sent[0]['uid']);
        self::assertSame('Channel #test is registered.', $sent[0]['msg']);
        self::assertSame('NOTICE', $sent[0]['type']);
    }

    #[Test]
    public function replyDoesNotSendWhenSenderIsNull(): void
    {
        $sent = [];
        $notifier = $this->createNotifier($sent);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');
        $context = $this->createContext(null, $notifier, $translator);

        $context->reply('test.key');

        self::assertSame([], $sent);
    }

    #[Test]
    public function replyRawSendsMessageDirectly(): void
    {
        $sent = [];
        $notifier = $this->createNotifier($sent);
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $this->createStub(TranslatorInterface::class),
        );

        $context->replyRaw('Raw message');

        self::assertCount(1, $sent);
        self::assertSame('Raw message', $sent[0]['msg']);
    }

    #[Test]
    public function replyRawDoesNotSendWhenSenderIsNull(): void
    {
        $sent = [];
        $notifier = $this->createNotifier($sent);
        $context = $this->createContext(null, $notifier, $this->createStub(TranslatorInterface::class));

        $context->replyRaw('Raw message');

        self::assertSame([], $sent);
    }

    #[Test]
    public function transWrapsParametersWithPercentSignsAndServicePlaceholders(): void
    {
        $notifier = $this->createStub(CRegServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CReG');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'test.key',
                $this->callback(static fn (array $params): bool => 'CReG' === ($params['%bot%'] ?? null)
                        && 'NickServ' === ($params['%nickserv%'] ?? null)
                        && '#test' === ($params['%channel%'] ?? null)),
                'cregserv',
                'en',
            )
            ->willReturn('Translated');

        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        self::assertSame('Translated', $context->trans('test.key', ['channel' => '#test']));
    }

    #[Test]
    public function transKeepsAlreadyWrappedParameterKeys(): void
    {
        $notifier = $this->createStub(CRegServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CReG');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with(
                'test.key',
                $this->callback(static fn (array $params): bool => '#test' === ($params['%channel%'] ?? null)),
                'cregserv',
                'en',
            )
            ->willReturn('Translated');

        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
        );

        $context->trans('test.key', ['%channel%' => '#test']);
    }

    #[Test]
    public function getNotifierReturnsInjectedNotifier(): void
    {
        $notifier = $this->createStub(CRegServNotifierInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $notifier,
            $this->createStub(TranslatorInterface::class),
        );

        self::assertSame($notifier, $context->getNotifier());
    }

    #[Test]
    public function getLanguageAndTimezoneReturnTheConfiguredValues(): void
    {
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $this->createStub(CRegServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
        );

        self::assertSame('en', $context->getLanguage());
        self::assertSame('Europe/Madrid', $context->getTimezone());
    }

    #[Test]
    public function formatDateReturnsFormattedDateInTimezone(): void
    {
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $this->createStub(CRegServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
        );

        $date = new DateTime('2024-01-15 10:30:00', new DateTimeZone('UTC'));
        $result = $context->formatDate($date);

        self::assertStringContainsString('15/01/2024', $result);
        self::assertStringContainsString('CET', $result);
    }

    #[Test]
    public function formatDateAcceptsDateTimeImmutable(): void
    {
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $this->createStub(CRegServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
        );

        $result = $context->formatDate(new DateTimeImmutable('2024-01-15 10:30:00', new DateTimeZone('UTC')));

        self::assertStringContainsString('15/01/2024', $result);
    }

    #[Test]
    public function formatDateReturnsEmDashWhenDateIsNull(): void
    {
        $context = $this->createContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            $this->createStub(CRegServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
        );

        self::assertSame('—', $context->formatDate(null));
    }

    #[Test]
    public function getRegistryReturnsTheInjectedRegistry(): void
    {
        $registry = new CRegServCommandRegistry([]);
        $context = new CRegServContext(
            new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip'),
            null,
            'ESTADO',
            [],
            $this->createStub(CRegServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );

        self::assertSame($registry, $context->getRegistry());
    }

    /**
     * @param list<array{uid: string, msg: string, type: string}> $sent
     */
    private function createNotifier(array &$sent): CRegServNotifierInterface
    {
        $notifier = $this->createStub(CRegServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CReG');
        $notifier->method('sendMessage')->willReturnCallback(
            static function (string $uid, string $msg, string $type) use (&$sent): void {
                $sent[] = ['uid' => $uid, 'msg' => $msg, 'type' => $type];
            },
        );

        return $notifier;
    }
}
