<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command;

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
                ['%bot%' => 'OperServ', '%param%' => 'value'],
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
    public function replyDoesNotCallNotifierWhenSenderIsNull(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects($this->never())->method('sendMessage');

        $translator = $this->createStub(TranslatorInterface::class);

        $context = $this->createContext(
            sender: null,
            notifier: $notifier,
            translator: $translator
        );

        $context->reply('test.key');
    }

    #[Test]
    public function replyRawSendsMessageViaNotifier(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $sender = $this->createSender(uid: 'userUid456');

        $notifier->expects($this->once())
            ->method('sendMessage')
            ->with('userUid456', 'Raw message here', 'privmsg');

        $context = $this->createContext(
            sender: $sender,
            notifier: $notifier,
            messageType: 'privmsg'
        );

        $context->replyRaw('Raw message here');
    }

    #[Test]
    public function replyRawDoesNotCallNotifierWhenSenderIsNull(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects($this->never())->method('sendMessage');

        $context = $this->createContext(sender: null, notifier: $notifier);

        $context->replyRaw('Raw message');
    }

    #[Test]
    public function formatDateReturnsEmDashWhenNull(): void
    {
        $context = $this->createContext();

        self::assertSame('—', $context->formatDate(null));
    }

    #[Test]
    public function formatDateFormatsDateWithTimezone(): void
    {
        $context = $this->createContext(timezone: 'UTC');

        $date = new DateTimeImmutable('2025-06-15 14:30:00', new DateTimeZone('UTC'));

        self::assertSame('15/06/2025 14:30 UTC', $context->formatDate($date));
    }

    #[Test]
    public function formatDateConvertsToConfiguredTimezone(): void
    {
        $context = $this->createContext(timezone: 'Europe/Madrid');

        $date = new DateTimeImmutable('2025-06-15 12:00:00', new DateTimeZone('UTC'));

        self::assertMatchesRegularExpression(
            '/15\/06\/2025 \d{2}:\d{2} CEST/',
            $context->formatDate($date)
        );
    }

    #[Test]
    public function transTranslatesWithBotNameWrapper(): void
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServ');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with(
                'translation.key',
                ['%bot%' => 'OperServ', '%user%' => 'TestUser'],
                'operserv',
                'es'
            )
            ->willReturn('Hola TestUser, soy OperServ');

        $context = $this->createContext(
            notifier: $notifier,
            translator: $translator,
            language: 'es'
        );

        self::assertSame('Hola TestUser, soy OperServ', $context->trans('translation.key', ['user' => 'TestUser']));
    }

    #[Test]
    public function transWrapsParamsWithPercentSigns(): void
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OS');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects($this->once())
            ->method('trans')
            ->with(
                'key',
                ['%bot%' => 'OS', '%name%' => 'John', '%count%' => 5],
                'operserv',
                'en'
            )
            ->willReturn('result');

        $context = $this->createContext(notifier: $notifier, translator: $translator);

        $context->trans('key', ['name' => 'John', 'count' => 5]);
    }

    #[Test]
    public function isRootReturnsFalseWhenSenderIsNull(): void
    {
        $rootRegistry = new RootUserRegistry('Admin');
        $accessHelper = $this->createAccessHelper(rootRegistry: $rootRegistry);

        $context = $this->createContext(sender: null, accessHelper: $accessHelper);

        self::assertFalse($context->isRoot());
    }

    #[Test]
    public function isRootDelegatesToAccessHelper(): void
    {
        $sender = $this->createSender(uid: 'uid123', nick: 'AdminNick');

        $rootRegistry = new RootUserRegistry('AdminNick,OtherRoot');
        $accessHelper = $this->createAccessHelper(rootRegistry: $rootRegistry);

        $context = $this->createContext(sender: $sender, accessHelper: $accessHelper);

        self::assertTrue($context->isRoot());
    }

    #[Test]
    public function isRootReturnsFalseWhenAccessHelperReturnsFalse(): void
    {
        $sender = $this->createSender(uid: 'uid456', nick: 'RegularUser');

        $rootRegistry = new RootUserRegistry('Admin');
        $accessHelper = $this->createAccessHelper(rootRegistry: $rootRegistry);

        $context = $this->createContext(sender: $sender, accessHelper: $accessHelper);

        self::assertFalse($context->isRoot());
    }

    #[Test]
    public function getBotNameReturnsNotifierNick(): void
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('OperServBot');

        $context = $this->createContext(notifier: $notifier);

        self::assertSame('OperServBot', $context->getBotName());
    }

    private function createSender(string $uid = 'uid123', string $nick = 'TestNick'): SenderView
    {
        return new SenderView(
            uid: $uid,
            nick: $nick,
            ident: 'testident',
            hostname: 'test.host',
            cloakedHost: 'test.cloaked',
            ipBase64: 'base64ip',
            isIdentified: false,
            isOper: false,
            serverSid: '001',
            displayHost: 'display.host'
        );
    }

    private function createAccessHelper(?RootUserRegistry $rootRegistry = null): IrcopAccessHelper
    {
        return new IrcopAccessHelper(
            rootUserRegistry: $rootRegistry ?? new RootUserRegistry(''),
            ircopRepository: $this->createStub(OperIrcopRepositoryInterface::class),
            roleRepository: $this->createStub(OperRoleRepositoryInterface::class),
        );
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
        );
    }
}
