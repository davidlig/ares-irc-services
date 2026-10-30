<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SetPrivateHandler;
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

#[CoversClass(SetPrivateHandler::class)]
#[CoversClass(UpdateChannelSetting::class)]
#[CoversClass(UpdateChannelSettingHandler::class)]
#[CoversClass(UpdateChannelSettingResult::class)]
final class SetPrivateHandlerTest extends TestCase
{
    #[Test]
    public function rejectsValuesOtherThanOnAndOff(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::never())->method('configurePrivate');
        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $this->handler($repository)->handle($this->context($notifier, $translator, 'maybe'), $channel, 'maybe');

        self::assertSame(["\x0304✗\x03\x0F presentation.error.syntax"], $messages);
    }

    #[Test]
    public function enablesAndDisablesPrivateAndPersistsBothChanges(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 1);
        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('save')->with($channel);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $handler = $this->handler($repository);

        $handler->handle($this->context($notifier, $translator, 'on'), $channel, ' on ');
        self::assertTrue($channel->isPrivate());
        $handler->handle($this->context($notifier, $translator, 'off'), $channel, 'OFF');
        self::assertFalse($channel->isPrivate());
        self::assertSame(["\x0303✔\x03\x0F set.private.on", "\x0303✔\x03\x0F set.private.off"], $messages);
    }

    #[Test]
    public function doesNothingWhenSettingRequestCannotBeBuiltWithoutSender(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 1);
        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::never())->method('save');
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $this->handler($repository)->handle($this->context($notifier, $translator, 'ON', withSender: false), $channel, 'ON');

        self::assertFalse($channel->isPrivate());
        self::assertSame([], $messages);
    }

    private function handler(RegisteredChannelRepositoryInterface $channels): SetPrivateHandler
    {
        return new SetPrivateHandler(new UpdateChannelSettingHandler(
            $this->createStub(EmailDomainPolicy::class),
            $channels,
            $this->createStub(ChanUserAccountPort::class),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
        ));
    }

    private function context(ChanServNotifierInterface $notifier, TranslatorInterface $translator, string $value, bool $withSender = true): ChanServContext
    {
        return new ChanServContext(
            $withSender ? new SenderView('UID1', 'User', 'ident', 'host', 'cloak', 'ip') : null,
            null,
            'SET',
            ['#test', 'PRIVATE', $value],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->serviceNicks(),
        );
    }

    private function serviceNicks(): ServiceNicknameRegistry
    {
        $provider = new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'chanserv';
            }

            public function getNickname(): string
            {
                return 'ChanServ';
            }
        };

        return new ServiceNicknameRegistry([$provider]);
    }
}
