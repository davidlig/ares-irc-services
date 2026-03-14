<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\AdminCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AdminCommand::class)]
final class AdminCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        bool $hasAdminMode = true,
    ): ChanServContext {
        $modeSupport = $this->createStub(\App\Application\Port\ChannelModeSupportInterface::class);
        $modeSupport->method('hasAdmin')->willReturn($hasAdminMode);

        return new ChanServContext(
            $sender,
            $senderAccount,
            'ADMIN',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            $modeSupport,
        );
    }

    #[Test]
    public function replyNotSupportedWhenChannelModeSupportHasNoAdmin(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AdminCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        $cmd->execute($this->createContext($sender, $account, ['#test', 'SomeNick'], $notifier, $translator, false));

        self::assertSame(['admin.not_supported'], $messages);
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AdminCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        $cmd->execute($this->createContext($sender, $account, ['notachannel', 'Nick'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replySyntaxWhenTargetNickEmpty(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AdminCommand(
            $channelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        $cmd->execute($this->createContext($sender, $account, ['#test', ''], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AdminCommand(
            $channelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelNotRegisteredException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'Nick'], $notifier, $translator));
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AdminCommand(
            $channelRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        $cmd->execute($this->createContext($sender, null, ['#test', 'Nick'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replyNickNotRegisteredWhenTargetNotInDb(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AdminCommand(
            $channelRepo,
            $nickRepo,
            $this->createStub(NetworkUserLookupPort::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        $cmd->execute($this->createContext($sender, $account, ['#test', 'UnregNick'], $notifier, $translator));

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function replyUserNotOnChannelWhenTargetNotOnNetwork(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(false);

        $targetAccount = $this->createStub(RegisteredNick::class);
        $targetAccount->method('getId')->willReturn(2);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($targetAccount);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AdminCommand(
            $channelRepo,
            $nickRepo,
            $userLookup,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        $cmd->execute($this->createContext($sender, $account, ['#test', 'OnNetworkNick'], $notifier, $translator));

        self::assertSame(['admin.user_not_on_channel'], $messages);
    }

    #[Test]
    public function successGrantsAdminAndReplies(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(1);
        $channel->method('isFounder')->willReturn(true);
        $channel->method('getName')->willReturn('#test');
        $channel->method('isSecure')->willReturn(false);

        $targetAccount = $this->createStub(RegisteredNick::class);
        $targetAccount->method('getId')->willReturn(2);
        $targetSender = new SenderView('UID2', 'TargetNick', 'i', 'h', 'c', 'ip');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($targetAccount);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($targetSender);

        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('setChannelMemberMode')->with('#test', 'UID2', 'a', true);
        $notifier->expects(self::once())->method('sendNoticeToChannel')->with(self::anything(), self::anything());
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AdminCommand(
            $channelRepo,
            $nickRepo,
            $userLookup,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );
        $cmd->execute($this->createContext($sender, $account, ['#test', 'TargetNick'], $notifier, $translator));

        self::assertSame(['admin.done'], $messages);
    }

    #[Test]
    public function getterMethodsReturnExpectedValues(): void
    {
        $cmd = new AdminCommand(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
        );

        self::assertSame('ADMIN', $cmd->getName());
        self::assertSame([], $cmd->getAliases());
        self::assertSame(2, $cmd->getMinArgs());
        self::assertSame('admin.syntax', $cmd->getSyntaxKey());
        self::assertSame('admin.help', $cmd->getHelpKey());
        self::assertSame(18, $cmd->getOrder());
        self::assertSame('admin.short', $cmd->getShortDescKey());
        self::assertSame([], $cmd->getSubCommandHelp());
        self::assertFalse($cmd->isOperOnly());
        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }
}
