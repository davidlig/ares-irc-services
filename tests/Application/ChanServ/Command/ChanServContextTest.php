<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command;

use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ChanServContext::class)]
final class ChanServContextTest extends TestCase
{
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
            ['mychan', 'INFO'],
        );

        self::assertNull($context->getChannelNameArg(0));
    }

    #[Test]
    public function getChannelNameArgReturnsNullWhenIndexMissing(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        self::assertNull($context->getChannelNameArg(0));
    }

    #[Test]
    public function getChannelViewDelegatesToLookup(): void
    {
        $view = new ChannelView('#test', '+nt', null, 0);
        $lookup = $this->createMock(ChannelLookupPort::class);
        $lookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            ['#test'],
            $lookup,
        );

        self::assertSame($view, $context->getChannelView('#test'));
    }

    #[Test]
    public function formatDateReturnsFormattedString(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
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
        $notifier = $this->createStub(ChanServNotifierInterface::class);
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
    public function formatDateConvertsDateTimeToImmutableAndFormats(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );
        $date = new DateTime('2024-06-15 14:30:00', new DateTimeZone('UTC'));

        $formatted = $context->formatDate($date);

        self::assertStringContainsString('15/06/2024', $formatted);
        self::assertMatchesRegularExpression('/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/', $formatted);
    }

    #[Test]
    public function getLanguageAndGetTimezoneReturnInjectedValues(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
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
        $registry = new ChanServCommandRegistry([]);
        $context = new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
            [],
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
        );

        self::assertSame($registry, $context->getRegistry());
    }

    #[Test]
    public function getNotifierGetChannelLookupGetChannelModeSupportReturnInjected(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $lookup = $this->createStub(ChannelLookupPort::class);
        $modeSupport = new NullChannelModeSupport();
        $context = new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
            [],
            $notifier,
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $lookup,
            $modeSupport,
            $this->createStub(NetworkUserLookupPort::class),
        );

        self::assertSame($notifier, $context->getNotifier());
        self::assertSame($lookup, $context->getChannelLookup());
        self::assertSame($modeSupport, $context->getChannelModeSupport());
    }

    #[Test]
    public function getUserLookupReturnsInjectedInstance(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $context = new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'INFO',
            [],
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $userLookup,
        );

        self::assertSame($userLookup, $context->getUserLookup());
    }

    #[Test]
    public function transDelegatesToTranslatorWithCatalogAndLocale(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('info.key', self::anything(), 'chanserv', 'en')
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
    public function replySendsMessageWhenSenderIsSet(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
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
        $notifier = $this->createMock(ChanServNotifierInterface::class);
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
        $notifier = $this->createStub(ChanServNotifierInterface::class);
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
    public function replyWithEmptyParamsTranslatesSuccessfully(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('test.key', ['%bot%' => 'ChanServ'], 'chanserv', 'en')
            ->willReturn('Translated message');
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        $context->reply('test.key');

        self::assertSame(['Translated message'], $messages);
    }

    #[Test]
    public function replyWithParamsWrapsPercentSigns(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('ChanServ');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('test.key', ['%bot%' => 'ChanServ', '%name%' => 'User', '%count%' => '5'], 'chanserv', 'en')
            ->willReturn('User has 5 items');
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $notifier,
            $translator,
            [],
        );

        $context->reply('test.key', ['name' => 'User', 'count' => '5']);

        self::assertSame(['User has 5 items'], $messages);
    }
}
