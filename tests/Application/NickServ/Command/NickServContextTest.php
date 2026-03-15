<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(NickServContext::class)]
final class NickServContextTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        array $args = [],
        ?NickServCommandRegistry $registry = null,
        ?PendingVerificationRegistry $pendingVerification = null,
        ?RecoveryTokenRegistry $recoveryToken = null,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'INFO',
            $args,
            $notifier,
            $translator,
            'en',
            'Europe/Madrid',
            'NOTICE',
            $registry ?? new NickServCommandRegistry([]),
            $pendingVerification ?? new PendingVerificationRegistry(),
            $recoveryToken ?? new RecoveryTokenRegistry(),
        );
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
        );

        self::assertSame($registry, $context->getRegistry());
    }

    #[Test]
    public function getNotifierGetPendingVerificationGetRecoveryTokenReturnInjected(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $pending = new PendingVerificationRegistry();
        $recovery = new RecoveryTokenRegistry();
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

    #[Test]
    public function transDelegatesToTranslatorWithCatalogAndLocale(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('info.key', self::anything(), 'nickserv', 'en')
            ->willReturn('Translated');
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        self::assertSame('Translated', $context->trans('info.key'));
    }

    #[Test]
    public function transInUsesExplicitLanguageWhenProvided(): void
    {
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('key', self::anything(), 'nickserv', 'es')
            ->willReturn('Traducido');
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
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('key', self::callback(static fn (array $p) => isset($p['%nick%']) && 'User' === $p['%nick%']), 'nickserv', 'en')
            ->willReturn('Hello User');
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        $context->reply('key', ['%nick%' => 'User']);
    }
}
