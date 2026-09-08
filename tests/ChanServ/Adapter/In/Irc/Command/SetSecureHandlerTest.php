<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SetSecureHandler;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecure;
use App\ChanServ\Application\UseCase\ConfigureSecure\ConfigureChannelSecureHandlerInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SetSecureHandler::class)]
final class SetSecureHandlerTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslationInterface $translator,
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
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function invalidValueRepliesSyntaxError(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $configureSecure = $this->createStub(ConfigureChannelSecureHandlerInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetSecureHandler($configureSecure);
        $handler->handle($this->createContext($notifier, $translator, 'yes'), $channel, 'yes');

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function onEnablesSecureDispatchesEventAndRepliesAndSendsNotice(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $configureSecure = $this->createMock(ConfigureChannelSecureHandlerInterface::class);
        $configureSecure->expects(self::once())->method('handle')->with(self::callback(
            static fn (ConfigureChannelSecure $command): bool => $channel === $command->channel && $command->enabled,
        ));
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

        $handler = new SetSecureHandler($configureSecure);
        $handler->handle($this->createContext($notifier, $translator, 'on'), $channel, ' ON ');

        self::assertSame(['set.secure.on'], $messages);
        self::assertCount(1, $channelNotices);
    }

    #[Test]
    public function offDisablesSecureDoesNotDispatchAndReplies(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#test');
        $configureSecure = $this->createMock(ConfigureChannelSecureHandlerInterface::class);
        $configureSecure->expects(self::once())->method('handle')->with(self::callback(
            static fn (ConfigureChannelSecure $command): bool => $channel === $command->channel && !$command->enabled,
        ));
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

        $handler = new SetSecureHandler($configureSecure);
        $handler->handle($this->createContext($notifier, $translator, 'off'), $channel, 'OFF');

        self::assertSame(['set.secure.off'], $messages);
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
