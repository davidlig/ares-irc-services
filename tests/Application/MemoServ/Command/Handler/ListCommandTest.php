<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\Handler\ListCommand;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelLevel;
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
            $this->createServiceNicks(),
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
    public function doesNothingWhenSenderNull(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
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
        $cmd->execute($this->createContext(null, $account, [], $notifier, $translator));

        self::assertSame([], $messages);
    }

    #[Test]
    public function throwsAccessDeniedWhenNoMemoRead(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(5);
        $channel->method('isFounder')->willReturn(false);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(new ChannelLevel(5, ChannelLevel::KEY_MEMOREAD, 300));
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);

        $this->expectException(\App\Domain\ChanServ\Exception\InsufficientAccessException::class);
        $cmd->execute($this->createContext($sender, $account, ['#mychan'], $notifier, $translator));
    }

    #[Test]
    public function listShowsUnreadMemoMarker(): void
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
        $memo = new Memo(10, null, 2, 'Unread message', new DateTimeImmutable('2025-03-14 12:00:00'));
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

        self::assertStringContainsString("\x0304*\x03", $messages[1]);
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

    #[Test]
    public function listShowsMultipleMemosWithIndexes(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(10);
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $senderNick1 = $this->createStub(RegisteredNick::class);
        $senderNick1->method('getNickname')->willReturn('Alice');
        $senderNick2 = $this->createStub(RegisteredNick::class);
        $senderNick2->method('getNickname')->willReturn('Bob');
        $senderNick3 = $this->createStub(RegisteredNick::class);
        $senderNick3->method('getNickname')->willReturn('Charlie');
        $nickRepo->method('findById')->willReturnMap([
            [2, $senderNick1],
            [3, $senderNick2],
            [4, $senderNick3],
        ]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memos = [
            new Memo(10, null, 2, 'First memo', new DateTimeImmutable('2025-03-14 10:00:00')),
            new Memo(10, null, 3, 'Second memo', new DateTimeImmutable('2025-03-14 11:00:00')),
            new Memo(10, null, 4, 'Third memo', new DateTimeImmutable('2025-03-14 12:00:00')),
        ];
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn($memos);
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
        self::assertStringContainsString('#1', $messages[1]);
        self::assertStringContainsString('Alice', $messages[1]);
        self::assertStringContainsString('#2', $messages[2]);
        self::assertStringContainsString('Bob', $messages[2]);
        self::assertStringContainsString('#3', $messages[3]);
        self::assertStringContainsString('Charlie', $messages[3]);
        self::assertSame('list.footer', $messages[4]);
    }

    #[Test]
    public function listHandlesEmptyMessageGracefully(): void
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
        $memo = new Memo(10, null, 2, '', new DateTimeImmutable());
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
        self::assertStringContainsString('Alice', $messages[1]);
    }

    #[Test]
    public function listSkipsNonMemoItems(): void
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
        $memo = new Memo(10, null, 2, 'Valid memo', new DateTimeImmutable('2025-03-14 12:00:00'));
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn(['invalid', $memo, 123, null]);
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
        self::assertStringContainsString('Valid memo', $messages[1]);
        self::assertCount(3, $messages);
    }

    #[Test]
    public function getNameReturnsList(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame('LIST', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame(0, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsListSyntax(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame('list.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsListHelp(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame('list.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturns3(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame(3, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsListShort(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame('list.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);
        $cmd = new ListCommand($nickRepo, $channelRepo, $memoRepo, $accessHelper);
        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
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
