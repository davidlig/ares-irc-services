<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\Handler\ListCommand;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\Memo;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ListCommand::class)]
final class ListCommandTest extends TestCase
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
            'LIST',
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
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, null, [], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replyEmptyWhenNoMemosForNick(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, [], $notifier, $translator));

        self::assertSame(['list.empty'], $messages);
    }

    #[Test]
    public function replyChannelNotRegisteredWhenChannelArgAndNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#unknown'], $notifier, $translator));

        self::assertSame(['list.channel_not_registered'], $messages);
    }

    #[Test]
    public function listShowsMemosForNickWithHeaderFooterAndLines(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $senderNick = $this->createStub(RegisteredNick::class);
        $senderNick->method('getNickname')->willReturn('Alice');
        $nickRepo->method('findById')->willReturn($senderNick);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memo = new Memo(10, null, 2, 'Short text', new DateTimeImmutable('2025-03-14 12:00:00'));
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn([$memo]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ($params['%target%'] ?? ''));

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, [], $notifier, $translator));

        self::assertSame('list.headerUser', $messages[0]);
        self::assertStringContainsString('Short text', $messages[1]);
        self::assertStringContainsString('Alice', $messages[1]);
        self::assertSame('list.footer', $messages[2]);
    }

    #[Test]
    public function listShowsPreviewTruncatedWhenMessageLong(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $senderNick = $this->createStub(RegisteredNick::class);
        $senderNick->method('getNickname')->willReturn('Bob');
        $nickRepo->method('findById')->willReturn($senderNick);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $longMessage = str_repeat('x', 60);
        $memo = new Memo(10, null, 3, $longMessage, new DateTimeImmutable());
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn([$memo]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, [], $notifier, $translator));

        self::assertStringContainsString('…', $messages[1]);
    }

    #[Test]
    public function listShowsSenderNickIdWhenSenderNickNotFound(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memo = new Memo(10, null, 999, 'Msg', new DateTimeImmutable());
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn([$memo]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, [], $notifier, $translator));

        self::assertStringContainsString('999', $messages[1]);
    }

    #[Test]
    public function listShowsChannelMemosWhenChannelRegisteredAndUserHasAccess(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(5);
        $channel->method('isFounder')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $senderNick = $this->createStub(RegisteredNick::class);
        $senderNick->method('getNickname')->willReturn('Other');
        $nickRepo->method('findById')->willReturn($senderNick);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memo = new Memo(null, 5, 2, 'Channel memo', new DateTimeImmutable());
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetChannel')->willReturn([$memo]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ($params['%target%'] ?? ''));

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#mychan'], $notifier, $translator));

        self::assertSame('list.header#mychan', $messages[0]);
        self::assertStringContainsString('Channel memo', $messages[1]);
        self::assertSame('list.footer', $messages[2]);
    }
}
