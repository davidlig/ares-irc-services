<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\ChannelRegisterThrottleRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\RegisterCommand;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RegisterCommand::class)]
final class RegisterCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
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
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['notachannel', 'desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyChannelNotOnNetworkWhenChannelViewNull(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.channel_not_on_network'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, null, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replyThrottledWhenCooldownRemaining(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.throttled'], $messages);
    }

    #[Test]
    public function replyLimitExceededWhenMaxChannelsReached(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $existing = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([$existing, $existing, $existing]);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.limit_exceeded'], $messages);
    }

    #[Test]
    public function successSavesChannelAndReplies(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->with(self::callback(static function ($ch): bool {
            if (!$ch instanceof \App\Domain\ChanServ\Entity\RegisteredChannel
                || '#test' !== strtolower($ch->getName())
                || 10 !== $ch->getFounderNickId()) {
                return false;
            }
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($ch, 1);

            return true;
        }));
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'My desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function dispatchesChannelRegisteredEventOnSuccess(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 42);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#mychannel', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $dispatchedEvent = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function ($event) use (&$dispatchedEvent): object {
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
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(true);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));
    }

    #[Test]
    public function replyThrottleExpiresWhenCooldownElapses(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function throwsWhenChannelAlreadyRegisteredByDifferentUser(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(true);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));
    }

    #[Test]
    public function doesNotThrottleDifferentAccounts(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(1);
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function bypassesThrottleWhenCooldownIsZero(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function normalizesChannelNameToLowercase(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('existsByChannelName')->with('#testchan')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#TestChan', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
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
            $this->createStub(EventDispatcherInterface::class),
            $this->createNonRootRegistry(),
        );

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    private function createNonRootRegistry(): RootUserRegistry
    {
        return new RootUserRegistry('');
    }

    private function createRootRegistryFor(string $nick): RootUserRegistry
    {
        return new RootUserRegistry($nick);
    }

    #[Test]
    public function operBypassesThrottle(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function rootBypassesThrottle(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createRootRegistryFor('RootAdmin'), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function operBypassesChannelLimit(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $existing = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([$existing, $existing, $existing]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function rootBypassesChannelLimit(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $existing = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([$existing, $existing, $existing]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createRootRegistryFor('RootAdmin'), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function operDoesNotRecordThrottle(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
        self::assertNull($throttle->getLastRegistrationAt(10));
    }

    #[Test]
    public function rootDoesNotRecordThrottle(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->expects(self::once())->method('save')->willReturnCallback(static function ($channel): void {
            $ref = new ReflectionProperty(\App\Domain\ChanServ\Entity\RegisteredChannel::class, 'id');
            $ref->setAccessible(true);
            $ref->setValue($channel, 1);
        });
        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::atLeastOnce())->method('save');
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createRootRegistryFor('RootAdmin'), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
        self::assertNull($throttle->getLastRegistrationAt(10));
    }

    #[Test]
    public function normalUserStillThrottledWhenCooldownActive(): void
    {
        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $throttle = new ChannelRegisterThrottleRegistry();
        $throttle->recordRegistration(10);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 3600);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.throttled'], $messages);
    }

    #[Test]
    public function normalUserStillLimitedByMaxChannels(): void
    {
        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: false);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $existing = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('existsByChannelName')->willReturn(false);
        $channelRepo->method('findByFounderNickId')->willReturn([$existing, $existing, $existing]);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $throttle = new ChannelRegisterThrottleRegistry();
        $channelView = new ChannelView('#test', '+n', null, 0);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, $this->createStub(EventDispatcherInterface::class), $this->createNonRootRegistry(), 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.limit_exceeded'], $messages);
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
