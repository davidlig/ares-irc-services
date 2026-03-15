<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\Handler\IgnoreCommand;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\MemoServ\Entity\MemoIgnore;
use App\Domain\MemoServ\Repository\MemoIgnoreRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IgnoreCommand::class)]
final class IgnoreCommandTest extends TestCase
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
            'IGNORE',
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
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['LIST'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replySyntaxErrorWhenSubCommandInvalid(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['INVALID'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function listRepliesEmptyWhenNoIgnores(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('listByTargetNick')->willReturn([]);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LIST'], $notifier, $translator));

        self::assertSame(['ignore.list_empty'], $messages);
    }

    #[Test]
    public function addRepliesNickNotRegisteredWhenTargetNotRegistered(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['ADD', 'UnknownNick'], $notifier, $translator));

        self::assertSame(['ignore.nick_not_registered'], $messages);
    }

    #[Test]
    public function addSuccessSavesAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignoreRepo = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn(null);
        $ignoreRepo->method('countByTargetNick')->willReturn(0);
        $ignoreRepo->expects(self::once())->method('save')->with(self::isInstanceOf(MemoIgnore::class));
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['ADD', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.added'], $messages);
    }

    #[Test]
    public function delRepliesNotIgnoredWhenNotInList(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn(null);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['DEL', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.not_ignored'], $messages);
    }

    #[Test]
    public function delSuccessDeletesAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $existing = $this->createStub(MemoIgnore::class);
        $ignoreRepo = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn($existing);
        $ignoreRepo->expects(self::once())->method('delete')->with($existing);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['DEL', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.removed'], $messages);
    }

    #[Test]
    public function addRepliesAlreadyIgnoredWhenAlreadyInList(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $existing = $this->createStub(MemoIgnore::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn($existing);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['ADD', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.already_ignored'], $messages);
    }

    #[Test]
    public function addRepliesLimitReachedWhenNickLimitExceeded(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetNickAndIgnored')->willReturn(null);
        $ignoreRepo->method('countByTargetNick')->willReturn(20);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['ADD', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.limit_reached_nick'], $messages);
    }

    #[Test]
    public function listShowsItemsWhenListNotEmpty(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredNick = $this->createStub(RegisteredNick::class);
        $ignoredNick->method('getId')->willReturn(2);
        $ignoredNick->method('getNickname')->willReturn('IgnoredUser');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($ignoredNick);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignore1 = $this->createStub(MemoIgnore::class);
        $ignore1->method('getIgnoredNickId')->willReturn(2);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('listByTargetNick')->willReturn([$ignore1]);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LIST'], $notifier, $translator));

        self::assertSame('ignore.list_header', $messages[0]);
        self::assertSame('  IgnoredUser', $messages[1]);
        self::assertSame('ignore.list_footer', $messages[2]);
    }

    #[Test]
    public function listUsesIdWhenNickNotFound(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignore1 = $this->createStub(MemoIgnore::class);
        $ignore1->method('getIgnoredNickId')->willReturn(42);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('listByTargetNick')->willReturn([$ignore1]);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LIST'], $notifier, $translator));

        self::assertSame('  42', $messages[1]);
    }

    #[Test]
    public function channelListRepliesChannelNotRegistered(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LIST', '#test'], $notifier, $translator));

        self::assertSame(['ignore.channel_not_registered'], $messages);
    }

    #[Test]
    public function replySyntaxErrorWhenEmptyNickToAdd(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['ADD'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function channelAddSuccessSavesAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $ignoreRepo = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn(null);
        $ignoreRepo->method('countByTargetChannel')->willReturn(0);
        $ignoreRepo->expects(self::once())->method('save')->with(self::isInstanceOf(MemoIgnore::class));
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['ADD', '#test', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.added'], $messages);
    }

    #[Test]
    public function channelAddRepliesLimitReachedWhenChannelLimitExceeded(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn(null);
        $ignoreRepo->method('countByTargetChannel')->willReturn(50);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['ADD', '#test', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.limit_reached_channel'], $messages);
    }

    #[Test]
    public function channelDelSuccessDeletesAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $existing = $this->createStub(MemoIgnore::class);
        $ignoreRepo = $this->createMock(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn($existing);
        $ignoreRepo->expects(self::once())->method('delete')->with($existing);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['DEL', '#test', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.removed'], $messages);
    }

    #[Test]
    public function channelListReturnsList(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredNick = $this->createStub(RegisteredNick::class);
        $ignoredNick->method('getId')->willReturn(2);
        $ignoredNick->method('getNickname')->willReturn('IgnoredUser');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($ignoredNick);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $ignore1 = $this->createStub(MemoIgnore::class);
        $ignore1->method('getIgnoredNickId')->willReturn(2);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('listByTargetChannel')->willReturn([$ignore1]);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LIST', '#test'], $notifier, $translator));

        self::assertSame('ignore.list_header', $messages[0]);
        self::assertSame('  IgnoredUser', $messages[1]);
        self::assertSame('ignore.list_footer', $messages[2]);
    }

    #[Test]
    public function channelDelRepliesNotIgnoredWhenNotInList(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn(null);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['DEL', '#test', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.not_ignored'], $messages);
    }

    #[Test]
    public function channelDelNickNotRegisteredRepliesError(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['DEL', '#test', 'Unknown'], $notifier, $translator));

        self::assertSame(['ignore.nick_not_registered'], $messages);
    }

    #[Test]
    public function channelAddRepliesAlreadyIgnoredWhenAlreadyInList(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $ignoredAccount = $this->createStub(RegisteredNick::class);
        $ignoredAccount->method('getId')->willReturn(2);
        $ignoredAccount->method('getNickname')->willReturn('Other');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($ignoredAccount);
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $existing = $this->createStub(MemoIgnore::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $ignoreRepo->method('findByTargetChannelAndIgnored')->willReturn($existing);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['ADD', '#test', 'Other'], $notifier, $translator));

        self::assertSame(['ignore.already_ignored'], $messages);
    }

    #[Test]
    public function replySyntaxErrorWhenEmptyNickToDel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $ignoreRepo = $this->createStub(MemoIgnoreRepositoryInterface::class);
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

        $cmd = new IgnoreCommand($nickRepo, $channelRepo, $ignoreRepo, $accessHelper, 20, 50);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['DEL'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }
}
