<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\InfoCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
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
            new \App\Application\NickServ\PendingVerificationRegistry(),
            new \App\Application\NickServ\RecoveryTokenRegistry(),
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
}
