<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(OperServContext::class)]
final class OperServContextTest extends TestCase
{
    #[Test]
    public function getNotifierReturnsCorrectValue(): void
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $context = $this->createContext(notifier: $notifier);

        self::assertSame($notifier, $context->getNotifier());
    }

    #[Test]
    public function getLanguageReturnsCorrectValue(): void
    {
        $context = $this->createContext(language: 'es');

        self::assertSame('es', $context->getLanguage());
    }

    #[Test]
    public function getTimezoneReturnsCorrectValue(): void
    {
        $context = $this->createContext(timezone: 'Europe/Madrid');

        self::assertSame('Europe/Madrid', $context->getTimezone());
    }

    #[Test]
    public function getRegistryReturnsCorrectValue(): void
    {
        $registry = new OperServCommandRegistry([]);
        $context = $this->createContext(registry: $registry);

        self::assertSame($registry, $context->getRegistry());
    }

    #[Test]
    public function getAccessHelperReturnsCorrectValue(): void
    {
        $accessHelper = $this->createAccessHelper();
        $context = $this->createContext(accessHelper: $accessHelper);

        self::assertSame($accessHelper, $context->getAccessHelper());
    }

    #[Test]
    public function replyCallsTranslatorAndNotifier(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with(
                'test.key',
                $this->arrayHasKey('%param%'),
                'operserv',
                'en'
            )
            ->willReturn('Translated message');

        $sender = $this->createSender(uid: 'testUid123');
        $notifier->expects($this->once())
            ->method('sendMessage')
            ->with('testUid123', 'Translated message', 'notice');

        $context = $this->createContext(
            sender: $sender,
            notifier: $notifier,
            translator: $translator,
            language: 'en',
            messageType: 'notice'
        );

        $context->reply('test.key', ['param' => 'value']);
    }

    #[Test]
    public function replyDoesNothingWhenSenderIsNull(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects($this->never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated');

        $context = $this->createContext(
            sender: null,
            notifier: $notifier,
            translator: $translator
        );

        $context->reply('test.key');
    }

    #[Test]
    public function replyRawDoesNotSendWhenSenderIsNull(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects($this->never())->method('sendMessage');

        $context = $this->createContext(sender: null, notifier: $notifier);

        $context->replyRaw('Raw message');
    }

    #[Test]
    public function formatDateReturnsEmDashWhenDateIsNull(): void
    {
        $context = $this->createContext();

        self::assertSame('—', $context->formatDate(null));
    }

    #[Test]
    public function formatDateFormatsInTimezone(): void
    {
        $context = $this->createContext(timezone: 'Europe/Madrid');
        $date = new DateTimeImmutable('2024-01-15 10:30:00', new DateTimeZone('UTC'));

        $result = $context->formatDate($date);

        self::assertStringContainsString('15/01/2024', $result);
    }

    #[Test]
    public function transReturnsTranslation(): void
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params): string => $id . '|' . ($params['%key%'] ?? ''));

        $context = $this->createContext(notifier: $notifier, translator: $translator);

        self::assertSame('test.key|value', $context->trans('test.key', ['key' => 'value']));
    }

    #[Test]
    public function commandAndArgsAreAccessible(): void
    {
        $context = $this->createContext(command: 'TESTCMD', args: ['arg1', 'arg2']);

        self::assertSame('TESTCMD', $context->command);
        self::assertSame(['arg1', 'arg2'], $context->args);
    }

    #[Test]
    public function languageAndTimezoneDefaultToProvidedValues(): void
    {
        $context = $this->createContext(language: 'fr', timezone: 'Europe/Paris');

        self::assertSame('fr', $context->getLanguage());
        self::assertSame('Europe/Paris', $context->getTimezone());
    }

    #[Test]
    public function isRootReturnsFalseWhenSenderIsNull(): void
    {
        $context = $this->createContext(sender: null);

        self::assertFalse($context->isRoot());
    }

    #[Test]
    public function isRootDelegatesToAccessHelper(): void
    {
        $sender = $this->createSender(nick: 'AdminNick');
        $rootRegistry = new RootUserRegistry('AdminNick');
        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class)
        );

        $context = $this->createContext(sender: $sender, accessHelper: $accessHelper);

        self::assertTrue($context->isRoot());
    }

    #[Test]
    public function isRootReturnsFalseWhenAccessHelperReturnsFalse(): void
    {
        $sender = $this->createSender(nick: 'RegularUser');
        $rootRegistry = new RootUserRegistry('');
        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class)
        );

        $context = $this->createContext(sender: $sender, accessHelper: $accessHelper);

        self::assertFalse($context->isRoot());
    }

    #[Test]
    public function getBotNameReturnsNotifierNick(): void
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');

        $context = $this->createContext(notifier: $notifier);

        self::assertSame('OperServ', $context->getBotName());
    }

    private function createSender(?string $uid = null, ?string $nick = null): SenderView
    {
        return new SenderView(
            $uid ?? 'UID123',
            $nick ?? 'TestNick',
            'i',
            'h',
            'c',
            'ip'
        );
    }

    private function createAccessHelper(): IrcopAccessHelper
    {
        return new IrcopAccessHelper(
            new RootUserRegistry(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class)
        );
    }

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
        ?SenderView $sender = null,
        ?RegisteredNick $senderAccount = null,
        string $command = 'TEST',
        array $args = [],
        ?OperServNotifierInterface $notifier = null,
        ?TranslatorInterface $translator = null,
        string $language = 'en',
        string $timezone = 'UTC',
        string $messageType = 'notice',
        ?OperServCommandRegistry $registry = null,
        ?IrcopAccessHelper $accessHelper = null,
    ): OperServContext {
        return new OperServContext(
            sender: $sender,
            senderAccount: $senderAccount,
            command: $command,
            args: $args,
            notifier: $notifier ?? $this->createStub(OperServNotifierInterface::class),
            translator: $translator ?? $this->createStub(TranslatorInterface::class),
            language: $language,
            timezone: $timezone,
            messageType: $messageType,
            registry: $registry ?? new OperServCommandRegistry([]),
            accessHelper: $accessHelper ?? $this->createAccessHelper(),
            serviceNicks: $this->createServiceNicks(),
        );
    }

    #[Test]
    public function getSenderReturnsSenderView(): void
    {
        $sender = $this->createSender(uid: 'UID123', nick: 'TestNick');
        $context = $this->createContext(sender: $sender);

        self::assertSame($sender, $context->getSender());
    }

    #[Test]
    public function getSenderAccountReturnsAccount(): void
    {
        $sender = $this->createSender(uid: 'UID123', nick: 'TestNick');
        $account = $this->createStub(RegisteredNick::class);
        $context = $this->createContext(sender: $sender, senderAccount: $account);

        self::assertSame($account, $context->getSenderAccount());
    }

    #[Test]
    public function getSenderReturnsNullWhenSenderIsNull(): void
    {
        $context = $this->createContext(sender: null, senderAccount: null);

        self::assertNull($context->getSender());
        self::assertNull($context->getSenderAccount());
    }
}
