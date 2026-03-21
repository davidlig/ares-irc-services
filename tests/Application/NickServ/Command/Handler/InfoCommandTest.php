<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\InfoCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(InfoCommand::class)]
final class InfoCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'INFO',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function replyNotRegisteredWhenAccountNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['SomeNick'], $notifier, $translator));

        self::assertSame(['info.not_registered'], $messages);
    }

    #[Test]
    public function replyNotRegisteredWhenAccountPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator));

        self::assertSame(['info.not_registered'], $messages);
    }

    #[Test]
    public function replyPrivateWhenPrivateAndNotOwner(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(true);
        $account->method('getNickname')->willReturn('OtherNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['OtherNick'], $notifier, $translator));

        self::assertSame(['info.private'], $messages);
    }

    #[Test]
    public function successRepliesHeaderAndFooter(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('User');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn(null);
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User'], $notifier, $translator));

        self::assertContains('info.header', $messages);
        self::assertContains('info.footer', $messages);
    }

    #[Test]
    public function replyForbiddenWithReason(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(true);
        $account->method('getNickname')->willReturn('BadNick');
        $account->method('getReason')->willReturn('Spam abuse');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['BadNick'], $notifier, $translator));

        self::assertContains('info.header', $messages);
        self::assertContains('info.status', $messages);
        self::assertContains('info.reason', $messages);
        self::assertContains('info.footer', $messages);
    }

    #[Test]
    public function showEmailToIdentifiedOwner(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('Owner');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn('owner@example.com');
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Owner', 'i', 'h', 'c', 'ip', true, false, '', ''), ['Owner'], $notifier, $translator));

        self::assertContains('info.email', $messages);
    }

    #[Test]
    public function lastSeenOnline(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('OnlineUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn(null);
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('U2', 'OnlineUser', 'i', 'h', 'c', 'ip', true, false, '', ''));
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['OnlineUser'], $notifier, $translator));

        self::assertContains('info.last_seen_online', $messages);
    }

    #[Test]
    public function lastSeenAtDate(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('OfflineUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(new DateTimeImmutable('2024-01-15 10:30:00'));
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn(null);
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['OfflineUser'], $notifier, $translator));

        self::assertContains('info.last_seen_at', $messages);
    }

    #[Test]
    public function vhostDisplay(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('VhostUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn(null);
        $account->method('getVhost')->willReturn('vhost.example.com');
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('hidden.example.com');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['VhostUser'], $notifier, $translator));

        self::assertContains('info.vhost', $messages);
    }

    #[Test]
    public function showSuspendedWithReason(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('SuspendedUser');
        $account->method('getStatus')->willReturn(NickStatus::Suspended);
        $account->method('isSuspended')->willReturn(true);
        $account->method('getReason')->willReturn('Spamming');
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn(null);
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['SuspendedUser'], $notifier, $translator));

        self::assertContains('info.status', $messages);
        self::assertContains('info.reason', $messages);
    }

    #[Test]
    public function showLastQuitMessage(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('QuitUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(new DateTimeImmutable('2024-01-15 10:30:00'));
        $account->method('getLastQuitMessage')->willReturn('Ping timeout');
        $account->method('getEmail')->willReturn(null);
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['QuitUser'], $notifier, $translator));

        self::assertContains('info.last_quit', $messages);
    }

    #[Test]
    public function showUserChannelsWhenIdentified(): void
    {
        $founderChannel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $founderChannel->method('getId')->willReturn(1);
        $founderChannel->method('getName')->willReturn('#founderchan');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('Owner');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn('owner@example.com');
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([$founderChannel]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Owner', 'i', 'h', 'c', 'ip', true, false, '', ''), ['Owner'], $notifier, $translator));

        self::assertContains('info.channels_header', $messages);
        self::assertContains('info.channels_entry_founder', $messages);
    }

    #[Test]
    public function showChannelsWithAccessOnly(): void
    {
        $access = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $access->method('getChannelId')->willReturn(1);
        $access->method('getLevel')->willReturn(200);
        $accessChannel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $accessChannel->method('getId')->willReturn(1);
        $accessChannel->method('getName')->willReturn('#accesschan');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('AccessUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn('access@example.com');
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([$access]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);
        $channelRepo->method('findByIds')->willReturn([$accessChannel]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'AccessUser', 'i', 'h', 'c', 'ip', true, false, '', ''), ['AccessUser'], $notifier, $translator));

        self::assertContains('info.channels_header', $messages);
        self::assertContains('info.channels_entry_access', $messages);
    }

    #[Test]
    public function showSuccessorChannelsWhenIdentified(): void
    {
        $successorChannel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $successorChannel->method('getId')->willReturn(2);
        $successorChannel->method('getName')->willReturn('#successorchan');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('SuccessorUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn('successor@example.com');
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([$successorChannel]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'SuccessorUser', 'i', 'h', 'c', 'ip', true, false, '', ''), ['SuccessorUser'], $notifier, $translator));

        self::assertContains('info.channels_header', $messages);
        self::assertContains('info.channels_entry_successor', $messages);
    }

    #[Test]
    public function lastSeenNeverWhenOfflineAndNoLastSeen(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('NeverSeenUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn(null);
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['NeverSeenUser'], $notifier, $translator));

        self::assertContains('info.last_seen_never', $messages);
    }

    #[Test]
    public function noVhostDisplayedWhenVhostIsNull(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('NoVhostUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn(null);
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'), ['NoVhostUser'], $notifier, $translator));

        self::assertNotContains('info.vhost', $messages);
    }

    #[Test]
    public function showChannelsWithAccessAndFounder(): void
    {
        $founderChannel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $founderChannel->method('getId')->willReturn(1);
        $founderChannel->method('getName')->willReturn('#founderchan');
        $accessEntry = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $accessEntry->method('getChannelId')->willReturn(2);
        $accessEntry->method('getLevel')->willReturn(100);
        $accessChannel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $accessChannel->method('getId')->willReturn(2);
        $accessChannel->method('getName')->willReturn('#accesschan');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('BothUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn('both@example.com');
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([$accessEntry]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([$founderChannel]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([]);
        $channelRepo->method('findByIds')->willReturn([$accessChannel]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'BothUser', 'i', 'h', 'c', 'ip', true, false, '', ''), ['BothUser'], $notifier, $translator));

        self::assertContains('info.channels_header', $messages);
        self::assertContains('info.channels_entry_founder', $messages);
        self::assertContains('info.channels_entry_access', $messages);
    }

    #[Test]
    public function showChannelsWithAccessAndSuccessor(): void
    {
        $successorChannel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $successorChannel->method('getId')->willReturn(1);
        $successorChannel->method('getName')->willReturn('#successorchan');
        $accessEntry = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $accessEntry->method('getChannelId')->willReturn(2);
        $accessEntry->method('getLevel')->willReturn(150);
        $accessChannel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $accessChannel->method('getId')->willReturn(2);
        $accessChannel->method('getName')->willReturn('#accesschan');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('isPrivate')->willReturn(false);
        $account->method('getNickname')->willReturn('AccessSuccessorUser');
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('isSuspended')->willReturn(false);
        $account->method('getRegisteredAt')->willReturn(new DateTimeImmutable());
        $account->method('getLastSeenAt')->willReturn(null);
        $account->method('getLastQuitMessage')->willReturn(null);
        $account->method('getEmail')->willReturn('accesssucc@example.com');
        $account->method('getVhost')->willReturn(null);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);
        $vhostResolver = new VhostDisplayResolver('');
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByNick')->willReturn([$accessEntry]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $channelRepo->method('findBySuccessorNickId')->willReturn([$successorChannel]);
        $channelRepo->method('findByIds')->willReturn([$accessChannel]);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InfoCommand($nickRepo, $userLookup, $vhostResolver, $accessRepo, $channelRepo);
        $cmd->execute($this->createContext(new SenderView('UID1', 'AccessSuccessorUser', 'i', 'h', 'c', 'ip', true, false, '', ''), ['AccessSuccessorUser'], $notifier, $translator));

        self::assertContains('info.channels_header', $messages);
        self::assertContains('info.channels_entry_successor', $messages);
        self::assertContains('info.channels_entry_access', $messages);
    }

    #[Test]
    public function getNameReturnsInfo(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertSame('INFO', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertSame('info.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertSame('info.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsSix(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertSame(6, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertSame('info.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = new InfoCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            new VhostDisplayResolver(''),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
        );
        self::assertNull($cmd->getRequiredPermission());
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
