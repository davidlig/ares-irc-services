<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\SetSecureHandler;
use App\Application\ChanServ\Event\ChannelSecureEnabledEvent;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetSecureHandler::class)]
final class SetSecureHandlerTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        string $value,
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'SET',
            ['#test', 'SECURE', $value],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
        );
    }

    #[Test]
    public function invalidValueRepliesSyntaxError(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetSecureHandler($channelRepo, $eventDispatcher);
        $handler->handle($this->createContext($notifier, $translator, 'yes'), $channel, 'yes');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function onEnablesSecureDispatchesEventAndRepliesAndSendsNotice(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('configureSecure')->with(true);
        $channel->method('getName')->willReturn('#test');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $dispatched = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $event) use (&$dispatched): bool {
                if ($event instanceof ChannelSecureEnabledEvent) {
                    $dispatched = $event;

                    return '#test' === $event->channelName;
                }

                return false;
            }))
            ->willReturnArgument(0);
        $messages = [];
        $channelNotices = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (string $ch, string $m) use (&$channelNotices): void {
            $channelNotices[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetSecureHandler($channelRepo, $eventDispatcher);
        $handler->handle($this->createContext($notifier, $translator, 'on'), $channel, ' ON ');

        self::assertInstanceOf(ChannelSecureEnabledEvent::class, $dispatched);
        self::assertSame(['set.secure.on'], $messages);
        self::assertCount(1, $channelNotices);
    }

    #[Test]
    public function offDisablesSecureDoesNotDispatchAndReplies(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('configureSecure')->with(false);
        $channel->method('getName')->willReturn('#test');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');
        $messages = [];
        $channelNotices = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (string $ch, string $m) use (&$channelNotices): void {
            $channelNotices[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetSecureHandler($channelRepo, $eventDispatcher);
        $handler->handle($this->createContext($notifier, $translator, 'off'), $channel, 'OFF');

        self::assertSame(['set.secure.off'], $messages);
        self::assertCount(1, $channelNotices);
    }
}
