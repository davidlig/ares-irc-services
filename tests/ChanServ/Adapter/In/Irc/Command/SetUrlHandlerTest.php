<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SetUrlHandler;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSetting;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingHandler;
use App\ChanServ\Application\UseCase\UpdateSetting\UpdateChannelSettingResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Shared\Application\Port\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetUrlHandler::class)]
#[CoversClass(UpdateChannelSetting::class)]
#[CoversClass(UpdateChannelSettingHandler::class)]
#[CoversClass(UpdateChannelSettingResult::class)]
final class SetUrlHandlerTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        bool $withoutSender = false,
    ): ChanServContext {
        return new ChanServContext(
            $withoutSender ? null : new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'SET',
            ['URL', 'https://example.com'],
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
    public function handleUpdatesUrlAndReplies(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateUrl')->with('https://example.com');
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with($channel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = $this->createHandler($repo);
        $handler->handle($this->createContext($notifier, $translator), $channel, '  https://example.com  ');

        self::assertSame(['set.url.updated'], $messages);
    }

    #[Test]
    public function handleClearsUrlWhenValueEmptyAfterTrim(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateUrl')->with(null);
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('save')->with($channel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = $this->createHandler($repo);
        $handler->handle($this->createContext($notifier, $translator), $channel, '   ');

        self::assertSame(['set.url.cleared'], $messages);
    }

    #[Test]
    public function missingSenderReturnsWithoutUpdating(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::never())->method('updateUrl');
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $this->createHandler($repo)->handle(
            $this->createContext(
                $this->createStub(ChanServNotifierInterface::class),
                $this->createStub(TranslatorInterface::class),
                true,
            ),
            $channel,
            'https://example.com',
        );
    }

    private function createHandler(RegisteredChannelRepositoryInterface $channels): SetUrlHandler
    {
        return new SetUrlHandler(new UpdateChannelSettingHandler(
            $channels,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(EventBusInterface::class),
        ));
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
