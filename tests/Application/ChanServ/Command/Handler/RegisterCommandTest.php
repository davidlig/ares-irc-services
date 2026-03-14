<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChannelRegisterThrottleRegistry;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\RegisterCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelView;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
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

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, 3, 0);
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

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'My desc'], $notifier, $translator, $channelLookup));

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

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, 3, 0);
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

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, 3, 3600);
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

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, 3, 0);
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

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, 3, 0);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'My channel'], $notifier, $translator, $channelLookup));

        self::assertSame(['register.success'], $messages);
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

        $cmd = new RegisterCommand($channelRepo, $levelRepo, $throttle, 3, 0);

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelAlreadyRegisteredException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'Desc'], $notifier, $translator, $channelLookup));
    }
}
