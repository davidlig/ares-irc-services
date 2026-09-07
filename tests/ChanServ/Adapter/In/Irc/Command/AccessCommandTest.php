<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\AccessCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelAccessChangedEvent;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

use function assert;

#[CoversClass(AccessCommand::class)]
final class AccessCommandTest extends TestCase
{
    /**
     * @param string[] $args
     */
    private function createContext(
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslationInterface $translator,
        bool $isLevelFounder = false,
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
            $isLevelFounder,
        );
    }

    /**
     * @return array{RegisteredChannelRepositoryInterface&Stub, ChannelAccessRepositoryInterface&Stub, ChanUserAccountPort&Stub, ChanServAccessHelper}
     */
    private function createStubReposAndHelper(): array
    {
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);

        return [
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $accessRepo,
            $this->createStub(ChanUserAccountPort::class),
            new ChanServAccessHelper($accessRepo, $levelRepo),
        ];
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
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
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, null, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function throwsWhenChannelNotRegistered(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));

        $this->expectException(ChannelNotRegisteredException::class);

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
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('listByChannel')->willReturn([]);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertSame(['access.list.empty{"%bot%":"","%nickserv%":"NickServ","%chanserv%":"ChanServ","%memoserv%":"MemoServ","%operserv%":"OperServ","%channel%":"#test"}'], $messages);
    }

    #[Test]
    public function listWithEntriesRepliesHeaderAndRawLines(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        $access1 = new ChannelAccess(1, 10, 100);
        $access2 = new ChannelAccess(1, 20, 50);
        $nick10 = new ChanAccountView(10, 'NickTen', 'en');
        $nick20 = new ChanAccountView(20, 'NickTwenty', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('listByChannel')->willReturn([$access1, $access2]);
        $nickRepo->method('findAccountById')->willReturnMap([[10, $nick10], [20, $nick20]]);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
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
        $account = new ChanAccountView(99, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepo->method('findByChannelAndKey')->willReturn(null);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $accessRepo->method('listByChannel')->willReturn([]);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));

        $this->expectException(InsufficientAccessException::class);

        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));
    }

    #[Test]
    public function unknownSubcommandRepliesAccessUnknownSub(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'INVALID'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('access.unknown_sub', $messages[0]);
    }

    #[Test]
    public function addSuccessNewEntrySavesAndReplies(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(2, 'OtherNick', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(0);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $saved = null;
        $accessRepo->method('save')->willReturnCallback(static function ($entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '100'], $notifier, $translator));

        self::assertSame(['access.add.done'], $messages);
        self::assertInstanceOf(ChannelAccess::class, $saved);
        self::assertSame(1, $saved->getChannelId());
        self::assertSame(2, $saved->getNickId());
        self::assertSame(100, $saved->getLevel());
    }

    #[Test]
    public function addSuccessWithWildcardIpDispatchesEventWithStarIp(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', '*');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(2, 'OtherNick', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(0);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $dispatchedIp = '';
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $e) use (&$dispatchedIp): object {
            assert($e instanceof ChannelAccessChangedEvent);
            $dispatchedIp = $e->performedByIp;

            return $e;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (): void {});
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $eventDispatcher);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '100'], $notifier, $translator));

        self::assertSame('*', $dispatchedIp);
    }

    #[Test]
    public function addSuccessWithInvalidBase64IpDispatchesEventWithRawIp(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', '!!!invalid!!!');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(2, 'OtherNick', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(0);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $dispatchedIp = '';
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $e) use (&$dispatchedIp): object {
            assert($e instanceof ChannelAccessChangedEvent);
            $dispatchedIp = $e->performedByIp;

            return $e;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (): void {});
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $eventDispatcher);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '100'], $notifier, $translator));

        self::assertSame('!!!invalid!!!', $dispatchedIp);
    }

    #[Test]
    public function addSyntaxErrorRepliesErrorSyntax(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '', '100'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function addLevelOutOfRangeRepliesAccessLevelRange(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'SomeNick', '0'], $notifier, $translator));

        self::assertSame(['access.level_range'], $messages);
    }

    #[Test]
    public function addNickNotRegisteredRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo->method('findAccountByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'Unregistered', '100'], $notifier, $translator));

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function addFounderNotInListRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(1, 'Founder', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(0);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'Founder', '100'], $notifier, $translator));

        self::assertSame(['access.founder_not_in_list'], $messages);
    }

    #[Test]
    public function delSuccessRemovesAndReplies(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(2, 'OtherNick', 'en');
        $existing = new ChannelAccess(1, 2, 50);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('findByChannelAndNick')->willReturn($existing);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $removed = null;
        $accessRepo->method('remove')->willReturnCallback(static function ($entity) use (&$removed): void {
            $removed = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'OtherNick'], $notifier, $translator));

        self::assertSame(['access.del.done'], $messages);
        self::assertSame($existing, $removed);
    }

    #[Test]
    public function delSyntaxErrorRepliesErrorSyntax(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', '   '], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function delNotInListRepliesAccessDelNotInList(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(2, 'OtherNick', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'OtherNick'], $notifier, $translator));

        self::assertSame(['access.del.not_in_list'], $messages);
    }

    #[Test]
    public function addCannotManageLevelWhenLevelGeSenderRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(2, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(3, 'OtherNick', 'en');

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
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '100'], $notifier, $translator));

        self::assertSame(['access.cannot_manage_level'], $messages);
    }

    #[Test]
    public function addExistingEntryUpdatesLevelAndReplies(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(2, 'OtherNick', 'en');
        $existing = new ChannelAccess(1, 2, 50);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(1);
        $accessRepo->method('findByChannelAndNick')->willReturn($existing);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $saved = null;
        $accessRepo->method('save')->willReturnCallback(static function ($entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '75'], $notifier, $translator));

        self::assertSame(['access.add.done'], $messages);
        self::assertSame($existing, $saved);
        self::assertSame(75, $existing->getLevel());
    }

    #[Test]
    public function addMaxEntriesRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(99, 'NewNick', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(ChannelAccess::MAX_ENTRIES_PER_CHANNEL);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'NewNick', '10'], $notifier, $translator));

        self::assertSame(['access.max_entries'], $messages);
    }

    #[Test]
    public function delCannotManageLevelRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(2, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(3, 'OtherNick', 'en');
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
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'OtherNick'], $notifier, $translator));

        self::assertSame(['access.cannot_manage_level'], $messages);
    }

    #[Test]
    public function delNickNotRegisteredRepliesError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'User', 'en');
        $channel = $this->createChannelMock(1, 1);
        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo->method('findAccountByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'Unregistered'], $notifier, $translator));

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function addCannotManageExistingEntryLevelRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Manager', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(2, 'Manager', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(3, 'TargetNick', 'en');

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
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'TargetNick', '100'], $notifier, $translator));

        self::assertSame(['access.cannot_manage_level'], $messages);
    }

    #[Test]
    public function getterMethodsReturnExpectedValues(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $this->createStub(ChannelLevelRepositoryInterface::class));
        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));

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
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $this->createStub(ChannelLevelRepositoryInterface::class));
        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));

        self::assertFalse($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $this->createStub(ChannelLevelRepositoryInterface::class));
        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    #[Test]
    public function levelFounderBypassesAccessListLevelCheck(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $access1 = new ChannelAccess(1, 10, 100);
        $nick10 = new ChanAccountView(10, 'NickTen', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('listByChannel')->willReturn([$access1]);
        $nickRepo->method('findAccountById')->willReturnMap([[10, $nick10]]);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator, true));

        self::assertStringContainsString('access.list.header', $messages[0]);
    }

    #[Test]
    public function levelFounderBypassesAccessAddLevelCheck(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(2, 'OtherNick', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(0);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $saved = null;
        $accessRepo->method('save')->willReturnCallback(static function ($entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'OtherNick', '100'], $notifier, $translator, true));

        self::assertSame(['access.add.done'], $messages);
        self::assertInstanceOf(ChannelAccess::class, $saved);
    }

    #[Test]
    public function levelFounderBypassesAccessDelLevelCheck(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(2, 'OtherNick', 'en');
        $existing = new ChannelAccess(1, 2, 50);

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('findByChannelAndNick')->willReturn($existing);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $removed = null;
        $accessRepo->method('remove')->willReturnCallback(static function ($entity) use (&$removed): void {
            $removed = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', 'OtherNick'], $notifier, $translator, true));

        self::assertSame(['access.del.done'], $messages);
        self::assertSame($existing, $removed);
    }

    #[Test]
    public function levelFounderAddRejectsFounderNickInList(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(1, 'Founder', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'Founder', '100'], $notifier, $translator, true));

        self::assertSame(['access.founder_not_in_list'], $messages);
    }

    #[Test]
    public function levelFounderAddRejectsWhenMaxEntriesExceeded(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = new ChanAccountView(1, 'Founder', 'en');
        $channel = $this->createChannelMock(1, 1);
        $targetNick = new ChanAccountView(99, 'NewNick', 'en');

        [$channelRepo, $accessRepo, $nickRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $accessRepo->method('countByChannel')->willReturn(ChannelAccess::MAX_ENTRIES_PER_CHANNEL);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $nickRepo->method('findAccountByNick')->willReturn($targetNick);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AccessCommand($channelRepo, $accessRepo, $nickRepo, $accessHelper, $this->createStub(EventBusInterface::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'NewNick', '10'], $notifier, $translator, true));

        self::assertSame(['access.max_entries'], $messages);
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
            public function __construct(private string $key, private string $nick) {}

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
