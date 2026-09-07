<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\RegisterCommand;
use App\ChanServ\Adapter\Out\InMemory\ChannelRegisterThrottleRegistry;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(RegisterCommand::class)]
final class RegisterCommandTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function requiredRegisterPrefixProvider(): array
    {
        return [
            'op' => ['o'],
            'admin' => ['a'],
            'owner' => ['q'],
        ];
    }

    /**
     * @param string[] $args
     */
    private function createContext(
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslationInterface $translator,
        ChannelLookupPort $channelLookup,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'REGISTER',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['notachannel', 'desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyChannelNotOnNetworkWhenChannelViewNull(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.channel_not_on_network'], $messages);
    }

    #[Test]
    public function replyInsufficientChannelRankWhenSenderIsNotChannelOperator(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->expects(self::never())->method('save');
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::never())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($this->channelViewWithSenderPrefix('#test', 'UID1', ['v']));

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.insufficient_channel_rank'], $messages);
    }

    #[Test]
    public function replyInsufficientChannelRankWhenSenderIsNotChannelMember(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->expects(self::never())->method('save');
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::never())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($this->channelViewWithSenderPrefix('#test', 'UID2', ['o']));

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.insufficient_channel_rank'], $messages);
    }

    #[Test]
    #[DataProvider('requiredRegisterPrefixProvider')]
    public function successWithRequiredChannelPrefix(string $prefixLetter): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($this->channelViewWithSenderPrefix('#test', 'UID1', [$prefixLetter]));

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function successUsesRoleLetterWhenPrefixLettersAreMissing(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView(
            '#test',
            '+n',
            null,
            1,
            [[
                'uid' => 'UID1',
                'roleLetter' => 'o',
            ]],
        ));

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, null, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replyThrottledWhenCooldownRemaining(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.throttled'], $messages);
    }

    #[Test]
    public function replyLimitExceededWhenMaxChannelsReached(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $existing = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([$existing, $existing, $existing]);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.limit_exceeded'], $messages);
    }

    #[Test]
    public function successSavesChannelAndReplies(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->with(self::callback(static function ($ch): bool {
            if (!$ch instanceof RegisteredChannel
                || '#test' !== strtolower($ch->getName())
                || 10 !== $ch->getFounderNickId()) {
                return false;
            }
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($ch, 1);

            return true;
        }));
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'My desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function dispatchesChannelRegisteredEventOnSuccess(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 42);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#mychannel');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $dispatchedEvent = null;
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $event) use (&$dispatchedEvent): object {
            $dispatchedEvent = $event;

            return $event;
        });

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $eventDispatcher, $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#mychannel', 'My channel description'], $notifier, $translator, $channelLookup));

        self::assertInstanceOf(ChannelRegisteredEvent::class, $dispatchedEvent);
        /* @var ChannelRegisteredEvent $dispatchedEvent */
        self::assertSame(42, $dispatchedEvent->channelId);
        self::assertSame('#mychannel', $dispatchedEvent->channelName);
        self::assertSame('#mychannel', $dispatchedEvent->channelNameLower);
    }

    #[Test]
    public function throwsWhenChannelAlreadyRegistered(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(true);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);

        $this->expectException(ChannelAlreadyRegisteredException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));
    }

    #[Test]
    public function repliesPendingDeletionWhenChannelIsPendingDeletion(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channel = RegisteredChannel::register('#test', 10, 'Desc');
        $channel->markPendingDeletion();
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(true);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $channelRepo,
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
            3,
            0,
        );

        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $this->createStub(ChannelLookupPort::class)));

        self::assertSame(['register.pending_deletion'], $messages);
    }

    #[Test]
    public function replyThrottleExpiresWhenCooldownElapses(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function throwsWhenChannelAlreadyRegisteredByDifferentUser(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(true);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);

        $this->expectException(ChannelAlreadyRegisteredException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));
    }

    #[Test]
    public function doesNotThrottleDifferentAccounts(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(2, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(1);
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function bypassesThrottleWhenCooldownIsZero(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function normalizesChannelNameToLowercase(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(10, 'User', 'en');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('existsByChannelName')->with('#testchan')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#TestChan');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#TESTCHAN', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertStringContainsString('register.success', $messages[0]);
    }

    #[Test]
    public function getNameReturnsRegister(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame('REGISTER', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsRegisterSyntax(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame('register.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsRegisterHelp(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame('register.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsOne(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame(1, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsRegisterShort(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame('register.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertFalse($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
            new ChannelRegisterThrottleRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    private function createNonRootRegistry(): ChanServOperatorAccess
    {
        $access = $this->createStub(ChanServOperatorAccess::class);
        $access->method('isRoot')->willReturn(false);

        return $access;
    }

    private function createRootRegistryFor(string $nick): ChanServOperatorAccess
    {
        $access = $this->createStub(ChanServOperatorAccess::class);
        $access->method('isRoot')->willReturnCallback(static fn (string $n): bool => 0 === strcasecmp($n, $nick));

        return $access;
    }

    #[Test]
    public function operBypassesThrottle(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = new ChanAccountView(10, 'User', 'en');
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function rootBypassesThrottle(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = new ChanAccountView(10, 'User', 'en');
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createRootRegistryFor('RootAdmin'), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function operBypassesChannelLimit(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = new ChanAccountView(10, 'User', 'en');
        $existing = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([$existing, $existing, $existing]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function rootBypassesChannelLimit(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = new ChanAccountView(10, 'User', 'en');
        $existing = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([$existing, $existing, $existing]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createRootRegistryFor('RootAdmin'), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function operDoesNotRecordThrottle(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = new ChanAccountView(10, 'User', 'en');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
        self::assertNull($throttle->getLastRegistrationAt(10));
    }

    #[Test]
    public function rootDoesNotRecordThrottle(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = new ChanAccountView(10, 'User', 'en');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function (RegisteredChannel $channel): void {
            $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createRootRegistryFor('RootAdmin'), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
        self::assertNull($throttle->getLastRegistrationAt(10));
    }

    #[Test]
    public function normalUserStillThrottledWhenCooldownActive(): void
    {
        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = new ChanAccountView(10, 'User', 'en');
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.throttled'], $messages);
    }

    #[Test]
    public function normalUserStillLimitedByMaxChannels(): void
    {
        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = new ChanAccountView(10, 'User', 'en');
        $existing = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([$existing, $existing, $existing]);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = $this->channelViewWithSenderPrefix('#test');
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventBusInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.limit_exceeded'], $messages);
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

    /** @param list<string> $prefixLetters */
    private function channelViewWithSenderPrefix(string $channelName, string $senderUid = 'UID1', array $prefixLetters = ['o']): ChannelView
    {
        return new ChannelView(
            $channelName,
            '+n',
            null,
            1,
            [[
                'uid' => $senderUid,
                'roleLetter' => $prefixLetters[0] ?? '',
                'prefixLetters' => $prefixLetters,
            ]],
        );
    }
}
