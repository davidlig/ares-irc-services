<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\SetMlockHandler;
use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Application\ChanServ\Service\MlockStateFromChannelResolver;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetMlockHandler::class)]
final class SetMlockHandlerTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ?ChannelLookupPort $channelLookup = null,
        ?ChannelModeSupportInterface $modeSupport = null,
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'SET',
            ['#test', 'MLOCK', 'on'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            $modeSupport ?? new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function invalidValueRepliesSyntaxError(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $resolver = new MlockStateFromChannelResolver();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetMlockHandler($channelRepo, $eventDispatcher, $resolver);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'maybe');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function onWithNoChannelViewConfiguresMlockActiveNoModesAndDispatches(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('configureMlock')->with(true, '', []);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getMlock')->willReturn('');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $lookup = $this->createMock(ChannelLookupPort::class);
        $lookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn(null);
        $dispatched = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $e) use (&$dispatched): bool {
                if ($e instanceof ChannelMlockUpdatedEvent) {
                    $dispatched = $e;

                    return '#test' === $e->channelName;
                }

                return false;
            }))
            ->willReturnArgument(0);
        $resolver = new MlockStateFromChannelResolver();
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

        $handler = new SetMlockHandler($channelRepo, $eventDispatcher, $resolver);
        $handler->handle($this->createContext($notifier, $translator, $lookup), $channel, ' ON ');

        self::assertInstanceOf(ChannelMlockUpdatedEvent::class, $dispatched);
        self::assertSame(['set.mlock.on'], $messages);
        self::assertCount(1, $channelNotices);
    }

    #[Test]
    public function onWithChannelViewResolvesModesAndConfiguresMlock(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('configureMlock')->with(true, '+nt', []);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getMlock')->willReturn('+nt');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $view = new ChannelView('#test', '+nt', null, 0);
        $lookup = $this->createMock(ChannelLookupPort::class);
        $lookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getChannelSettingModesUnsetWithoutParam')->willReturn(['n', 't']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn([]);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnArgument(0);
        $resolver = new MlockStateFromChannelResolver();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetMlockHandler($channelRepo, $eventDispatcher, $resolver);
        $handler->handle($this->createContext($notifier, $translator, $lookup, $support), $channel, 'on');

        self::assertSame(['set.mlock.on'], $messages);
    }

    #[Test]
    public function offDisablesMlockSavesAndReplies(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('configureMlock')->with(false, '', []);
        $channel->method('getName')->willReturn('#test');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');
        $resolver = new MlockStateFromChannelResolver();
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

        $handler = new SetMlockHandler($channelRepo, $eventDispatcher, $resolver);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'OFF');

        self::assertSame(['set.mlock.off'], $messages);
        self::assertCount(1, $channelNotices);
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
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
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
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
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
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
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
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

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }
}
