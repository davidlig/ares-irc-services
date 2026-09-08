<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SetMlockHandler;
use App\ChanServ\Adapter\In\Irc\MlockStateFromChannelResolver;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlock;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlockResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeName;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetMlockHandler::class)]
final class SetMlockHandlerTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslationInterface $translator,
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
        $configureMlock = $this->createStub(ConfigureChannelMlockHandlerInterface::class);
        $resolver = new MlockStateFromChannelResolver();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetMlockHandler($configureMlock, $resolver);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'maybe');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function onWithNoChannelViewConfiguresMlockActiveNoModesAndDispatches(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $lookup = $this->createMock(ChannelLookupPort::class);
        $lookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn(null);
        $configureMlock = $this->createMock(ConfigureChannelMlockHandlerInterface::class);
        $configureMlock->expects(self::once())->method('handle')->with(self::callback(
            static fn (ConfigureChannelMlock $command): bool => $channel === $command->channel && $command->modeLock->active && [] === $command->modeLock->settings,
        ))->willReturn(new ConfigureChannelMlockResult(ChannelModeLock::active()));
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetMlockHandler($configureMlock, $resolver);
        $handler->handle($this->createContext($notifier, $translator, $lookup), $channel, ' ON ');

        self::assertSame(['set.mlock.on'], $messages);
        self::assertCount(1, $channelNotices);
    }

    #[Test]
    public function onWithChannelViewResolvesModesAndConfiguresMlock(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $view = new ChannelView('#test', '+nt', null, 0);
        $lookup = $this->createMock(ChannelLookupPort::class);
        $lookup->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($view);
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getChannelSettingModesUnsetWithoutParam')->willReturn(['n', 't']);
        $support->method('getChannelSettingModesUnsetWithParam')->willReturn([]);
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $support->method('getPermanentChannelModeLetter')->willReturn('P');
        $configureMlock = $this->createMock(ConfigureChannelMlockHandlerInterface::class);
        $configureMlock->expects(self::once())->method('handle')->with(self::callback(static fn (ConfigureChannelMlock $command): bool => $channel === $command->channel
                && ['n', 't'] === array_map(static fn ($setting): string => $setting->mode->value, $command->modeLock->settings)))->willReturn(new ConfigureChannelMlockResult(ChannelModeLock::active([
                    new ChannelSetting(new ModeName('n')),
                    new ChannelSetting(new ModeName('t')),
                ])));
        $resolver = new MlockStateFromChannelResolver();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetMlockHandler($configureMlock, $resolver);
        $handler->handle($this->createContext($notifier, $translator, $lookup, $support), $channel, 'on');

        self::assertSame(['set.mlock.on'], $messages);
    }

    #[Test]
    public function offDisablesMlockSavesAndReplies(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $configureMlock = $this->createMock(ConfigureChannelMlockHandlerInterface::class);
        $configureMlock->expects(self::once())->method('handle')->with(self::callback(
            static fn (ConfigureChannelMlock $command): bool => $channel === $command->channel && !$command->modeLock->active,
        ))->willReturn(new ConfigureChannelMlockResult(ChannelModeLock::inactive()));
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetMlockHandler($configureMlock, $resolver);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'OFF');

        self::assertSame(['set.mlock.off'], $messages);
        self::assertCount(1, $channelNotices);
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
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
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
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
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
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
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
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

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }
}
