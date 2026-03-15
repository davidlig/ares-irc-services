<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\Handler\SendCommand;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\MemoServ\MemoServSendThrottleRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\Memo;
use App\Domain\MemoServ\Entity\MemoIgnore;
use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SendCommand::class)]
final class SendCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        MemoServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): MemoServContext {
        return new MemoServContext(
            $sender,
            $senderAccount,
            'SEND',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
        );
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['Other', 'Hi'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replySyntaxErrorWhenMessageEmpty(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['Other', ''], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function replyMessageTooLongWhenExceedsMaxLength(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $longMessage = str_repeat('x', Memo::MESSAGE_MAX_LENGTH + 1);
        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['Other', $longMessage], $notifier, $translator));

        self::assertSame(['send.message_too_long'], $messages);
    }

    #[Test]
    public function replyThrottledWhenCooldownActive(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $throttle = new MemoServSendThrottleRegistry();
        $throttle->recordSend('UID1');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 60);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['Other', 'Hi'], $notifier, $translator));

        self::assertSame(['send.throttled'], $messages);
    }

    #[Test]
    public function replyNickNotRegisteredWhenTargetNotRegistered(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['UnknownNick', 'Hi'], $notifier, $translator));

        self::assertSame(['send.nick_not_registered'], $messages);
    }

    #[Test]
    public function replyCannotSendToSelfWhenTargetIsSender(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['User', 'Hi'], $notifier, $translator));

        self::assertSame(['send.cannot_send_to_self'], $messages);
    }

    #[Test]
    public function replyIgnoredWhenRecipientIgnoresSender(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $recipient = $this->createStub(RegisteredNick::class);
        $recipient->method('getId')->willReturn(2);
        $recipient->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($recipient);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetNick')->willReturn(0);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn($this->createStub(MemoIgnore::class));
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForNick')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['Other', 'Hi'], $notifier, $translator));

        self::assertSame(['send.ignored'], $messages);
    }

    #[Test]
    public function successSendsToNickAndReplies(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $recipient = $this->createStub(RegisteredNick::class);
        $recipient->method('getId')->willReturn(2);
        $recipient->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($recipient);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetNick')->willReturn(0);
        $memoRepo->expects(self::once())->method('save')->with(self::isInstanceOf(Memo::class));
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForNick')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['Other', 'Hello'], $notifier, $translator));

        self::assertSame(['send.sent_nick'], $messages);
    }

    #[Test]
    public function replyChannelNotRegisteredWhenSendingToChannel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'Hi'], $notifier, $translator));

        self::assertSame(['send.channel_not_registered'], $messages);
    }

    #[Test]
    public function successSendsToChannelAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetChannel')->willReturn(0);
        $memoRepo->expects(self::once())->method('save')->with(self::isInstanceOf(Memo::class));
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForChannel')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', 'Hello channel'], $notifier, $translator));

        self::assertSame(['send.sent_channel'], $messages);
    }

    #[Test]
    public function replyLimitReachedWhenNickMemoLimitExceeded(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $recipient = $this->createStub(RegisteredNick::class);
        $recipient->method('getId')->willReturn(2);
        $recipient->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($recipient);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetNick')->willReturn(20);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForNick')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['Other', 'Hello'], $notifier, $translator));

        self::assertSame(['send.limit_reached'], $messages);
    }

    #[Test]
    public function replyLimitReachedWhenChannelMemoLimitExceeded(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetChannel')->willReturn(50);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForChannel')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['#test', 'Hello'], $notifier, $translator));

        self::assertSame(['send.limit_reached'], $messages);
    }

    #[Test]
    public function replyIgnoredWhenChannelIgnoresSender(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetChannel')->willReturn(0);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn($this->createStub(MemoIgnore::class));
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForChannel')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['#test', 'Hello'], $notifier, $translator));

        self::assertSame(['send.ignored'], $messages);
    }

    #[Test]
    public function throwsMemoDisabledExceptionWhenNickMemosDisabled(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $recipient = $this->createStub(RegisteredNick::class);
        $recipient->method('getId')->willReturn(2);
        $recipient->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($recipient);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForNick')->willReturn(false);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $notifier = $this->createStub(MemoServNotifierInterface::class);

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);

        $this->expectException(\App\Domain\MemoServ\Exception\MemoDisabledException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['Other', 'Hello'], $notifier, $translator));
    }

    #[Test]
    public function throwsMemoDisabledExceptionWhenChannelMemosDisabled(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForChannel')->willReturn(false);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $notifier = $this->createStub(MemoServNotifierInterface::class);

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);

        $this->expectException(\App\Domain\MemoServ\Exception\MemoDisabledException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['#test', 'Hello'], $notifier, $translator));
    }

    #[Test]
    public function sendsNoNotificationWhenRecipientHasZeroUnreadMemos(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $recipient = $this->createStub(RegisteredNick::class);
        $recipient->method('getId')->willReturn(2);
        $recipient->method('getNickname')->willReturn('Other');
        $recipient->method('getLanguage')->willReturn('en');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($recipient);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetNick')->willReturn(0);
        $memoRepo->method('countUnreadByTargetNick')->willReturn(0);
        $memoRepo->expects(self::once())->method('save')->with(self::isInstanceOf(Memo::class));
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForNick')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $recipientView = new SenderView('UID2', 'Other', 'i', 'h', 'c', 'ip');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($recipientView);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], ?string $domain = null, ?string $locale = null): string => $id);

        $messages = [];
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->expects(self::never())->method('sendNotice');

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['Other', 'Hello'], $notifier, $translator));

        self::assertSame(['send.sent_nick'], $messages);
    }

    #[Test]
    public function doesNotNotifyWhenSenderIsNull(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(null, $account, ['Other', 'Hi'], $notifier, $translator));

        self::assertSame([], $messages);
    }

    #[Test]
    public function sendsNotificationWhenRecipientOnline(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $recipient = $this->createStub(RegisteredNick::class);
        $recipient->method('getId')->willReturn(2);
        $recipient->method('getNickname')->willReturn('Other');
        $recipient->method('getLanguage')->willReturn('en');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($recipient);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetNick')->willReturn(0);
        $memoRepo->method('countUnreadByTargetNick')->willReturn(3);
        $memoRepo->expects(self::once())->method('save')->with(self::isInstanceOf(Memo::class));
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForNick')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $recipientView = new SenderView('UID2', 'Other', 'i', 'h', 'c', 'ip');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($recipientView);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = [], ?string $domain = null, ?string $locale = null): string => $id);

        $messages = [];
        $notices = [];
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->expects(self::once())->method('sendNotice')->willReturnCallback(static function (string $uid, string $msg) use (&$notices): void {
            $notices[] = ['uid' => $uid, 'msg' => $msg];
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['Other', 'Hello'], $notifier, $translator));

        self::assertSame(['send.sent_nick'], $messages);
        self::assertCount(1, $notices);
        self::assertSame('UID2', $notices[0]['uid']);
        self::assertSame('notify.nick_pending', $notices[0]['msg']);
    }

    #[Test]
    public function successSendsToNickIgnoresSenderWhenIgnored(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $senderAccount->method('getNickname')->willReturn('User');
        $recipient = $this->createStub(RegisteredNick::class);
        $recipient->method('getId')->willReturn(2);
        $recipient->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($recipient);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::never())->method('save');
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn($this->createStub(MemoIgnore::class));
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForNick')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['Other', 'Hello'], $notifier, $translator));

        self::assertSame(['send.ignored'], $messages);
    }

    #[Test]
    public function successSendsToChannelIgnoresSenderWhenIgnored(): void
    {
        $senderAccount = $this->createStub(RegisteredNick::class);
        $senderAccount->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::never())->method('save');
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn($this->createStub(MemoIgnore::class));
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForChannel')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $senderAccount, ['#test', 'Hello'], $notifier, $translator));

        self::assertSame(['send.ignored'], $messages);
    }

    #[Test]
    public function channelMemoSavesWithCorrectChannelId(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(42);
        $channel->method('getName')->willReturn('#mychan');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetChannel')->willReturn(0);
        $memoRepo->expects(self::once())->method('save')->with(self::callback(static fn ($memo): bool => $memo instanceof Memo
                && null === $memo->getTargetNickId()
                && 42 === $memo->getTargetChannelId()));
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForChannel')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#mychan', 'Hello channel'], $notifier, $translator));

        self::assertSame(['send.sent_channel'], $messages);
    }

    #[Test]
    public function channelMemoAcceptsWhitespaceOnlyMessage(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetChannel')->willReturn(0);
        $memoRepo->expects(self::once())->method('save');
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForChannel')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', "   \n\t  "], $notifier, $translator));

        self::assertSame(['send.sent_channel'], $messages);
    }

    #[Test]
    public function channelMemoAllowsMaxLengthMessage(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#test');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetChannel')->willReturn(0);
        $memoRepo->expects(self::once())->method('save');
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn(null);
        $settingsRepo = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settingsRepo->method('isEnabledForChannel')->willReturn(true);
        $throttle = new MemoServSendThrottleRegistry();
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $maxMessage = str_repeat('x', Memo::MESSAGE_MAX_LENGTH);
        $cmd = new SendCommand($nickRepo, $channelRepo, $memoRepo, $ignoreRepo, $settingsRepo, $throttle, $accessHelper, $userLookup, $translator, 'en', 20, 50, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test', $maxMessage], $notifier, $translator));

        self::assertSame(['send.sent_channel'], $messages);
    }

    #[Test]
    public function accessorGetNameReturnsSend(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame('SEND', $cmd->getName());
    }

    #[Test]
    public function accessorGetAliasesReturnsEmptyArray(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function accessorGetMinArgsReturnsTwo(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function accessorGetSyntaxKeyReturnsSendSyntax(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame('send.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function accessorGetHelpKeyReturnsSendHelp(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame('send.help', $cmd->getHelpKey());
    }

    #[Test]
    public function accessorGetOrderReturnsOne(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame(1, $cmd->getOrder());
    }

    #[Test]
    public function accessorGetShortDescKeyReturnsSendShort(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame('send.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function accessorGetSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function accessorIsOperOnlyReturnsFalse(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function accessorGetRequiredPermissionReturnsIdentified(): void
    {
        $cmd = new SendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoIgnoreRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new MemoServSendThrottleRegistry(),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            20,
            50,
            0,
        );

        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }
}
