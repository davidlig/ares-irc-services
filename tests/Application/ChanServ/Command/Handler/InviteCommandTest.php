<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\InviteCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(InviteCommand::class)]
final class InviteCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'INVITE',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
        );
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InviteCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['x'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InviteCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['#test'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $account = $this->createStub(RegisteredNick::class);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InviteCommand($channelRepo, $accessHelper);
        $this->expectException(\App\Domain\ChanServ\Exception\ChannelNotRegisteredException::class);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));
    }

    #[Test]
    public function successInvitesAndRepliesAndSendsNotice(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $access = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $access->method('getLevel')->willReturn(200);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $messages = [];
        $noticesToChannel = [];
        $invites = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (string $ch, string $m) use (&$noticesToChannel): void {
            $noticesToChannel[] = [$ch, $m];
        });
        $notifier->method('inviteToChannel')->willReturnCallback(static function (string $ch, string $uid) use (&$invites): void {
            $invites[] = [$ch, $uid];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new InviteCommand($channelRepo, $accessHelper);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['#test'], $notifier, $translator));

        self::assertSame(['invite.done'], $messages);
        self::assertCount(1, $invites);
        self::assertSame('#test', $invites[0][0]);
        self::assertSame('UID1', $invites[0][1]);
        self::assertCount(1, $noticesToChannel);
    }
}
