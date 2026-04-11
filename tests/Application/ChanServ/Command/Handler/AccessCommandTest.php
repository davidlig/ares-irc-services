<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\AccessCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AccessCommand::class)]
final class AccessCommandTest extends TestCase
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
            'ACCESS',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createStubReposAndHelper(): array
    {
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);

        return [
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $accessRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            new ChanServAccessHelper($accessRepo, $levelRepo),
        ];
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['notachannel', 'LIST'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $channel = RegisteredChannel::register('#test', 1, 'Desc');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, null, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelNotRegisteredException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));
    }

    private function createChannelMock(int $channelId = 1, int $founderNickId = 1): RegisteredChannel
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn($channelId);
        $channel->method('getFounderNickId')->willReturn($founderNickId);
        $channel->method('isFounder')->willReturnCallback(static fn (int $id): bool => $id === $founderNickId);
        $channel->method('getName')->willReturn('#test');

        return $channel;
    }

    #[Test]
    public function listWithEmptyEntriesRepliesHeaderAndEmpty(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('listByChannel')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame(['access.list.empty{"%bot%":"","%nickserv%":"NickServ","%chanserv%":"ChanServ","%memoserv%":"MemoServ","%operserv%":"OperServ","%channel%":"#test"}'], $messages);
    }

    #[Test]
    public function listWithEntriesRepliesHeaderAndRawLines(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        $access1 = new ChannelAccess(1, 10, 100);
        $access2 = new ChannelAccess(1, 20, 50);
        $nick10 = $this->createStub(RegisteredNick::class);
        $nick10->method('getNickname')->willReturn('NickTen');
        $nick20 = $this->createStub(RegisteredNick::class);
        $nick20->method('getNickname')->willReturn('NickTwenty');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('listByChannel')->willReturn([$access1, $access2]);
        $nickRepo->method('findById')->willReturnMap([[10, $nick10], [20, $nick20]]);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame([
            'access.list.header{"%bot%":"","%nickserv%":"NickServ","%chanserv%":"ChanServ","%memoserv%":"MemoServ","%operserv%":"OperServ","%channel%":"#test"}',
            'access.list.entry{"%bot%":"","%nickserv%":"NickServ","%chanserv%":"ChanServ","%memoserv%":"MemoServ","%operserv%":"OperServ","%index%":"1","%nickname%":"NickTen","%level%":"100"}',
            'access.list.entry{"%bot%":"","%nickserv%":"NickServ","%chanserv%":"ChanServ","%memoserv%":"MemoServ","%operserv%":"OperServ","%index%":"2","%nickname%":"NickTwenty","%level%":"50"}',
        ], $messages);
    }

    #[Test]
    public function listInsufficientAccessThrows(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(99);
        $channel = $this->createChannelMock(1, 1);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $accessRepo->method('listByChannel')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);

        $this->expectException(\App\Domain\ChanServ\Exception\InsufficientAccessException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));
    }

    #[Test]
    public function unknownSubcommandRepliesAccessUnknownSub(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'INVALID'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('access.unknown_sub', $messages[0]);
    }

    #[Test]
    public function addSuccessNewEntrySavesAndReplies(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(2);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(0);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn($targetNick);

        $saved = null;
        $accessRepo->method('save')->willReturnCallback(static function ($entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '100'], $notifier, $translator));

        self::assertSame(['access.add.done'], $messages);
        self::assertInstanceOf(ChannelAccess::class, $saved);
        self::assertSame(1, $saved->getChannelId());
        self::assertSame(2, $saved->getNickId());
        self::assertSame(100, $saved->getLevel());
    }

    #[Test]
    public function addSyntaxErrorRepliesErrorSyntax(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '', '100'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function addLevelOutOfRangeRepliesAccessLevelRange(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'SomeNick', '0'], $notifier, $translator));

        self::assertSame(['access.level_range'], $messages);
    }

    #[Test]
    public function addNickNotRegisteredRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo->method('findByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'Unregistered', '100'], $notifier, $translator));

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function addFounderNotInListRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(1);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(0);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn($targetNick);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'Founder', '100'], $notifier, $translator));

        self::assertSame(['access.founder_not_in_list'], $messages);
    }

    #[Test]
    public function delSuccessRemovesAndReplies(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(2);
        $existing = new ChannelAccess(1, 2, 50);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('findByChannelAndNick')->willReturn($existing);
        $nickRepo->method('findByNick')->willReturn($targetNick);

        $removed = null;
        $accessRepo->method('remove')->willReturnCallback(static function ($entity) use (&$removed): void {
            $removed = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'OtherNick'], $notifier, $translator));

        self::assertSame(['access.del.done'], $messages);
        self::assertSame($existing, $removed);
    }

    #[Test]
    public function delSyntaxErrorRepliesErrorSyntax(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', '   '], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function delNotInListRepliesAccessDelNotInList(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(2);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn($targetNick);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'OtherNick'], $notifier, $translator));

        self::assertSame(['access.del.not_in_list'], $messages);
    }

    #[Test]
    public function addCannotManageLevelWhenLevelGeSenderRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(3);

        $senderAccess = new ChannelAccess(1, 2, 50);
        $accessChangeLevel = $this->createStub(ChannelLevel::class);
        $accessChangeLevel->method('getValue')->willReturn(10);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturnCallback(static fn (int $c, string $k): ?ChannelLevel => ChannelLevel::KEY_ACCESSCHANGE === $k ? $accessChangeLevel : null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('countByChannel')->willReturn(1);
        $accessRepo->method('findByChannelAndNick')->willReturnMap([[1, 2, $senderAccess], [1, 3, null]]);
        $accessRepo->method('listByChannel')->willReturn([$senderAccess]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($targetNick);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '100'], $notifier, $translator));

        self::assertSame(['access.cannot_manage_level'], $messages);
    }

    #[Test]
    public function addExistingEntryUpdatesLevelAndReplies(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(2);
        $existing = new ChannelAccess(1, 2, 50);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(1);
        $accessRepo->method('findByChannelAndNick')->willReturn($existing);
        $nickRepo->method('findByNick')->willReturn($targetNick);

        $saved = null;
        $accessRepo->method('save')->willReturnCallback(static function ($entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '75'], $notifier, $translator));

        self::assertSame(['access.add.done'], $messages);
        self::assertSame($existing, $saved);
        self::assertSame(75, $existing->getLevel());
    }

    #[Test]
    public function addMaxEntriesRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(99);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(ChannelAccess::MAX_ENTRIES_PER_CHANNEL);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn($targetNick);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'NewNick', '10'], $notifier, $translator));

        self::assertSame(['access.max_entries'], $messages);
    }

    #[Test]
    public function delCannotManageLevelRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(3);
        $senderAccess = new ChannelAccess(1, 2, 50);
        $existing = new ChannelAccess(1, 3, 100);

        $accessChangeLevel = $this->createStub(ChannelLevel::class);
        $accessChangeLevel->method('getValue')->willReturn(10);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturnCallback(static fn (int $c, string $k): ?ChannelLevel => ChannelLevel::KEY_ACCESSCHANGE === $k ? $accessChangeLevel : null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturnMap([[1, 2, $senderAccess], [1, 3, $existing]]);
        $accessRepo->method('listByChannel')->willReturn([$senderAccess, $existing]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($targetNick);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'OtherNick'], $notifier, $translator));

        self::assertSame(['access.cannot_manage_level'], $messages);
    }

    #[Test]
    public function delNickNotRegisteredRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo->method('findByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'Unregistered'], $notifier, $translator));

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function addCannotManageExistingEntryLevelRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Manager', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(2);
        $channel = $this->createChannelMock(1, 1);
        $targetNick = $this->createStub(RegisteredNick::class);
        $targetNick->method('getId')->willReturn(3);

        $senderAccess = new ChannelAccess(1, 2, 150);
        $existingTargetAccess = new ChannelAccess(1, 3, 200);
        $accessChangeLevel = $this->createStub(ChannelLevel::class);
        $accessChangeLevel->method('getValue')->willReturn(10);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturnCallback(static fn (int $c, string $k): ?ChannelLevel => ChannelLevel::KEY_ACCESSCHANGE === $k ? $accessChangeLevel : null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('countByChannel')->willReturn(2);
        $accessRepo->method('findByChannelAndNick')->willReturnMap([[1, 2, $senderAccess], [1, 3, $existingTargetAccess]]);
        $accessRepo->method('listByChannel')->willReturn([$senderAccess, $existingTargetAccess]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($targetNick);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'TargetNick', '100'], $notifier, $translator));

        self::assertSame(['access.cannot_manage_level'], $messages);
    }

    #[Test]
    public function getterMethodsReturnExpectedValues(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $this->createStub(ChannelLevelRepositoryInterface::class));
        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);

        self::assertSame('ACCESS', $cmd->getName());
        self::assertSame([], $cmd->getAliases());
        self::assertSame(2, $cmd->getMinArgs());
        self::assertSame('access.syntax', $cmd->getSyntaxKey());
        self::assertSame('access.help', $cmd->getHelpKey());
        self::assertSame(8, $cmd->getOrder());
        self::assertSame('access.short', $cmd->getShortDescKey());
        self::assertCount(3, $cmd->getSubCommandHelp());
        self::assertFalse($cmd->isOperOnly());
        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $this->createStub(ChannelLevelRepositoryInterface::class));
        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper);

        self::assertFalse($cmd->allowsSuspendedChannel());
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
