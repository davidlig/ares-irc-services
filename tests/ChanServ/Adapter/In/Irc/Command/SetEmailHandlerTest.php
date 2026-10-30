<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SetEmailHandler;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\EmailDomainPolicy;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelHistoryService;
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
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetEmailHandler::class)]
#[CoversClass(UpdateChannelSetting::class)]
#[CoversClass(UpdateChannelSettingHandler::class)]
#[CoversClass(UpdateChannelSettingResult::class)]
final class SetEmailHandlerTest extends TestCase
{
    #[Test]
    public function invalidEmailRepliesInvalid(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = $this->createHandler($channelRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'not-an-email');

        self::assertSame(["\x0304✗\x03\x0F set.email.invalid"], $messages);
    }

    #[Test]
    public function validEmailUpdatesAndRepliesUpdated(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateEmail')->with('chan@example.com');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = $this->createHandler($channelRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, '  chan@example.com  ');

        self::assertSame(["\x0303✔\x03\x0F set.email.updated"], $messages);
    }

    #[Test]
    public function emptyValueClearsEmailAndRepliesCleared(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateEmail')->with(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = $this->createHandler($channelRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, '   ');

        self::assertSame(["\x0303✔\x03\x0F set.email.cleared"], $messages);
    }

    #[Test]
    public function emptyStringClearsEmail(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateEmail')->with(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = $this->createHandler($channelRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, '');

        self::assertSame(["\x0303✔\x03\x0F set.email.cleared"], $messages);
    }

    #[Test]
    public function complexValidEmailUpdatesCorrectly(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('updateEmail')->with('user+tag@sub.domain.example.com');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = $this->createHandler($channelRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'user+tag@sub.domain.example.com');

        self::assertSame(["\x0303✔\x03\x0F set.email.updated"], $messages);
    }

    #[Test]
    public function missingSenderReturnsWithoutUpdating(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::never())->method('updateEmail');
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::never())->method('save');

        $this->createHandler($repo)->handle(
            $this->createContext(
                $this->createStub(ChanServNotifierInterface::class),
                $this->createStub(TranslatorInterface::class),
                true,
            ),
            $channel,
            'user@example.com',
        );
    }

    #[Test]
    public function blockedEmailRequiresExplicitFounderLevelPermissionNotMerelyIrcopMode(): void
    {
        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$levelFounder, $ircop]) {
            $channel = RegisteredChannel::register(new DateTimeImmutable('2026-10-25'), '#example', 1, 'Example');
            new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 1);
            $channel->updateEmail('original@example.org');
            $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
            $channels->expects($levelFounder ? self::once() : self::never())->method('save');
            $policy = $this->createMock(EmailDomainPolicy::class);
            $policy->expects($levelFounder ? self::never() : self::once())->method('isBlocked')->with('user@blocked.org')->willReturn(true);
            $messages = [];
            $notifier = $this->createStub(ChanServNotifierInterface::class);
            $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
            $context = $this->createContext($notifier, $translator, levelFounder: $levelFounder, ircop: $ircop);
            $this->createHandler($channels, $policy)->handle($context, $channel, 'user@blocked.org');
            self::assertSame($levelFounder ? 'user@blocked.org' : 'original@example.org', $channel->getEmail());
            self::assertSame([$levelFounder ? "\x0303✔\x03\x0F set.email.updated" : "\x0304✗\x03\x0F presentation.error.email_domain_blocked"], $messages);
        }
    }

    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        bool $withoutSender = false,
        bool $levelFounder = false,
        bool $ircop = false,
    ): ChanServContext {
        return new ChanServContext(
            $withoutSender ? null : new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', true, $ircop),
            null,
            'SET',
            ['EMAIL', 'test@example.com'],
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
            isLevelFounder: $levelFounder,
        );
    }

    private function createHandler(RegisteredChannelRepositoryInterface $channels, ?EmailDomainPolicy $emailPolicy = null): SetEmailHandler
    {
        return new SetEmailHandler(new UpdateChannelSettingHandler(
            $emailPolicy ?? $this->createStub(EmailDomainPolicy::class),
            $channels,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
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
