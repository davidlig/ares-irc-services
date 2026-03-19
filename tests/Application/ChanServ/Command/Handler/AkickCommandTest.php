<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\AkickCommand;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelAkick;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(AkickCommand::class)]
final class AkickCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ?ChannelLookupPort $channelLookup = null,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'AKICK',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
        );
    }

    private function createStubReposAndHelper(): array
    {
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);

        return [
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $akickRepo,
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $accessRepo,
            new ChanServAccessHelper($accessRepo, $levelRepo),
        ];
    }

    private function createChannelMock(int $channelId = 1, int $founderNickId = 1): RegisteredChannelRepositoryInterface
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn($channelId);
        $channel->method('getFounderNickId')->willReturn($founderNickId);
        $channel->method('isFounder')->willReturnCallback(static fn (int $id): bool => $id === $founderNickId);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        return $channelRepo;
    }

    #[Test]
    public function replyInvalidChannelWhenFirstArgNotChannel(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        [$channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['notachannel', 'LIST'], $notifier, $translator));

        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function listEmptyEntriesRepliesHeaderAndEmpty(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([]);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.list.empty', $messages[0]);
        self::assertStringContainsString('#test', $messages[0]);
    }

    #[Test]
    public function unknownSubcommandRepliesAkickUnknownSub(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        [$_, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'INVALID'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.unknown_sub', $messages[0]);
    }

    #[Test]
    public function addSuccessCreatesAkickAndSetsBan(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Spammer'], $notifier, $translator));

        self::assertSame(['akick.add.done'], $messages);
        self::assertNotNull($saved);
        self::assertSame('*!*@*.isp.com', $saved->getMask());
        self::assertSame('Spammer', $saved->getReason());
    }

    #[Test]
    public function addAlreadyExistsRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(1);
        $existingAkick = $this->createStub(ChannelAkick::class);
        $akickRepo->method('findByChannelAndMask')->willReturn($existingAkick);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.add.already_exists', $messages[0]);
    }

    #[Test]
    public function addMaxEntriesRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(ChannelAkick::MAX_ENTRIES_PER_CHANNEL);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.max_entries', $messages[0]);
    }

    #[Test]
    public function addInvalidMaskRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
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

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'invalid-mask'], $notifier, $translator));

        self::assertSame(['akick.invalid_mask'], $messages);
    }

    #[Test]
    public function addDangerousMaskRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.dangerous_mask', $messages[0]);
        self::assertStringContainsString('*!*@*', $messages[0]);
    }

    #[Test]
    public function delByMaskRemovesAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getMask')->willReturn('*!*@*.isp.com');
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findByChannelAndMask')->willReturn($akick);

        $removed = false;
        $akickRepo->method('remove')->willReturnCallback(static function () use (&$removed): void {
            $removed = true;
        });

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', '*!*@*.isp.com'], $notifier, $translator));

        self::assertTrue($removed);
        self::assertSame(['akick.del.done'], $messages);
    }

    #[Test]
    public function delByNumberRemovesAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getMask')->willReturn('*!*@*.isp.com');
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

        $removed = false;
        $akickRepo->method('remove')->willReturnCallback(static function () use (&$removed): void {
            $removed = true;
        });

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', '1'], $notifier, $translator));

        self::assertTrue($removed);
        self::assertSame(['akick.del.done'], $messages);
    }

    #[Test]
    public function delNotFoundRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $akickRepo->method('listByChannel')->willReturn([]);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', '*!*@*.isp.com'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.del.not_found', $messages[0]);
    }

    #[Test]
    public function delInvalidNumberRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickOne = $this->createStub(ChannelAkick::class);
        $akickOne->method('getMask')->willReturn('*!*@one.com');
        $akickTwo = $this->createStub(ChannelAkick::class);
        $akickTwo->method('getMask')->willReturn('*!*@two.com');
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akickOne, $akickTwo]);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', '999'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.del.not_found', $messages[0]);
    }

    #[Test]
    public function listWithEntriesShowsAll(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akickOne = $this->createStub(ChannelAkick::class);
        $akickOne->method('getMask')->willReturn('*!*@one.com');
        $akickOne->method('getReason')->willReturn('Spammer');
        $akickOne->method('getCreatorNickId')->willReturn(2);
        $akickOne->method('getExpiresAt')->willReturn(null);

        $akickTwo = $this->createStub(ChannelAkick::class);
        $akickTwo->method('getMask')->willReturn('*!*@two.com');
        $akickTwo->method('getReason')->willReturn(null);
        $akickTwo->method('getCreatorNickId')->willReturn(1);
        $akickTwo->method('getExpiresAt')->willReturn(null);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akickOne, $akickTwo]);

        $creatorNick = $this->createStub(RegisteredNick::class);
        $creatorNick->method('getNickname')->willReturn('Founder');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnMap([[2, $creatorNick], [1, $account]]);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertCount(3, $messages);
        self::assertStringContainsString('akick.list.header', $messages[0]);
        self::assertStringContainsString('*!*@one.com', $messages[1]);
        self::assertStringContainsString('*!*@two.com', $messages[2]);
    }

    #[Test]
    public function addWithoutSenderAccountRepliesNotIdentified(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        [$channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();
        $channelRepo = $this->createChannelMock(1, 1);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, null, ['#test', 'ADD', '*!*@*.isp.com'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function addMissingArgsRepliesSyntax(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        [$_, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function delMissingArgsRepliesSyntax(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        [$_, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function addWithExpiryCreatesAkickWithExpiry(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Spammer', 'never'], $notifier, $translator));

        self::assertSame(['akick.add.done'], $messages);
        self::assertNotNull($saved);
        self::assertSame('*!*@*.isp.com', $saved->getMask());
        self::assertSame('Spammer', $saved->getReason());
        self::assertNull($saved->getExpiresAt());
    }

    #[Test]
    public function addWithEmptyReasonSetsReasonToNull(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '   '], $notifier, $translator));

        self::assertSame(['akick.add.done'], $messages);
        self::assertNotNull($saved);
        self::assertNull($saved->getReason());
    }

    #[Test]
    public function enforceNotFoundRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $akickRepo->method('listByChannel')->willReturn([]);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ENFORCE', '*!*@*.isp.com'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.enforce.not_found', $messages[0]);
    }

    #[Test]
    public function enforceExpiredRepliesExpired(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $expiredAkick = $this->createStub(ChannelAkick::class);
        $expiredAkick->method('isExpired')->willReturn(true);
        $expiredAkick->method('getMask')->willReturn('*!*@*.isp.com');

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findByChannelAndMask')->willReturn($expiredAkick);

        $removed = false;
        $akickRepo->method('remove')->willReturnCallback(static function () use (&$removed): void {
            $removed = true;
        });

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ENFORCE', '*!*@*.isp.com'], $notifier, $translator));

        self::assertTrue($removed);
        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.enforce.expired', $messages[0]);
    }

    #[Test]
    public function enforceByNumberFindsCorrectAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('isExpired')->willReturn(false);
        $akick->method('getMask')->willReturn('*!*@*.isp.com');
        $akick->method('getReason')->willReturn('Spam');

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 0, []);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ENFORCE', '1'], $notifier, $translator, $channelLookup));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.enforce.no_match', $messages[0]);
    }

    #[Test]
    public function enforceNoMembersMatchingRepliesNoMatch(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('isExpired')->willReturn(false);
        $akick->method('getMask')->willReturn('*!*@*.badsite.com');
        $akick->method('getReason')->willReturn('Spam');
        $akick->method('matches')->willReturn(false);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findByChannelAndMask')->willReturn($akick);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 0, [['uid' => 'UID1', 'roleLetter' => '']]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ENFORCE', '*!*@*.badsite.com'], $notifier, $translator, $channelLookup));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.enforce.no_match', $messages[0]);
    }

    #[Test]
    public function enforceKicksMatchingMembers(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('isExpired')->willReturn(false);
        $akick->method('getMask')->willReturn('*!*@*.isp.com');
        $akick->method('getReason')->willReturn('Spam');
        $akick->method('matches')->willReturn(true);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findByChannelAndMask')->willReturn($akick);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $kicked = [];
        $notifier->method('kickFromChannel')->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
            $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 2, [
            ['uid' => 'UID2', 'roleLetter' => ''],
            ['uid' => 'UID3', 'roleLetter' => ''],
        ]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $badUserView = new SenderView('UID2', 'BadUser', 'bad', 'bad.isp.com', 'bad.isp.com', 'aaa', false, false, '001', 'bad.isp.com');
        $otherUserView = new SenderView('UID3', 'OtherUser', 'other', 'other.com', 'other.com', 'bbb', false, false, '001', 'other.com');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturnMap([
            ['UID2', $badUserView],
            ['UID3', $otherUserView],
        ]);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'ENFORCE', '*!*@*.isp.com'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $userLookup,
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup);
        $cmd->execute($context);

        self::assertCount(2, $kicked);
        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.enforce.done', $messages[0]);
    }

    #[Test]
    public function enforceMissingArgsRepliesSyntax(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        [$_, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ENFORCE'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function enforceEmptyMaskRepliesSyntax(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        [$_, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ENFORCE', '   '], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function getterMethodsReturnExpectedValues(): void
    {
        [$channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();
        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));

        self::assertSame('AKICK', $cmd->getName());
        self::assertSame([], $cmd->getAliases());
        self::assertSame(2, $cmd->getMinArgs());
        self::assertSame('akick.syntax', $cmd->getSyntaxKey());
        self::assertSame('akick.help', $cmd->getHelpKey());
        self::assertSame(9, $cmd->getOrder());
        self::assertSame('akick.short', $cmd->getShortDescKey());
        self::assertCount(4, $cmd->getSubCommandHelp());
        self::assertFalse($cmd->isOperOnly());
        self::assertSame('IDENTIFIED', $cmd->getRequiredPermission());
    }

    #[Test]
    public function addEmptyMaskRepliesSyntax(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        [$_, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '   '], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function delEmptyItemRepliesSyntax(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        [$_, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', '   '], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function enforceChannelNotOnNetworkRepliesError(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('isExpired')->willReturn(false);
        $akick->method('getMask')->willReturn('*!*@*.isp.com');
        $akick->method('getReason')->willReturn('Spam');

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findByChannelAndMask')->willReturn($akick);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup);
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ENFORCE', '*!*@*.isp.com'], $notifier, $translator, $channelLookup));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.channel_not_registered', $messages[0]);
    }

    #[Test]
    public function delByInvalidNumberRepliesNotFound(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'DEL', '0'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.del.not_found', $messages[0]);
    }

    #[Test]
    public function listWithExpiryDateShowsFormattedExpiry(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $expiryDate = new DateTimeImmutable('+7 days');
        $akickWithExpiry = $this->createStub(ChannelAkick::class);
        $akickWithExpiry->method('getMask')->willReturn('*!*@withexpiry.com');
        $akickWithExpiry->method('getReason')->willReturn('Test');
        $akickWithExpiry->method('getCreatorNickId')->willReturn(1);
        $akickWithExpiry->method('getExpiresAt')->willReturn($expiryDate);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akickWithExpiry]);

        $creatorNick = $this->createStub(RegisteredNick::class);
        $creatorNick->method('getNickname')->willReturn('Founder');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($creatorNick);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertCount(2, $messages);
        self::assertStringContainsString('akick.list.header', $messages[0]);
        self::assertStringContainsString('*!*@withexpiry.com', $messages[1]);
    }

    #[Test]
    public function listWithUnknownCreatorShowsNumericId(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getMask')->willReturn('*!*@test.com');
        $akick->method('getReason')->willReturn('Test');
        $akick->method('getCreatorNickId')->willReturn(999);
        $akick->method('getExpiresAt')->willReturn(null);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertCount(2, $messages);
        self::assertStringContainsString('999', $messages[1]);
    }

    #[Test]
    public function addWithNeverExpiryCreatesPermanentAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m): void {
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Reason', 'never'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNull($saved->getExpiresAt());
    }

    #[Test]
    public function addWithZeroExpiryCreatesPermanentAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m): void {
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Reason', '0'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNull($saved->getExpiresAt());
    }

    #[Test]
    public function addWithInvalidExpiryFormatCreatesPermanentAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m): void {
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Reason', 'invalid'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNull($saved->getExpiresAt());
    }

    #[Test]
    public function enforceWithMatchingUsersKicksThem(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('isExpired')->willReturn(false);
        $akick->method('getMask')->willReturn('*!*@*.isp.com');
        $akick->method('getReason')->willReturn('Spam');
        $akick->method('matches')->willReturn(true);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('findByChannelAndMask')->willReturn($akick);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $kickedCount = 0;
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m): void {
        });
        $notifier->method('kickFromChannel')->willReturnCallback(static function () use (&$kickedCount): void {
            ++$kickedCount;
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 2, [
            ['uid' => 'UID2', 'roleLetter' => ''],
        ]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $userView = new SenderView('UID2', 'BadUser', 'bad', 'bad.isp.com', 'bad.isp.com', 'aaa', false, false, '001', 'bad.isp.com');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($userView);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'ENFORCE', '*!*@*.isp.com'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $userLookup,
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup);
        $cmd->execute($context);

        self::assertSame(1, $kickedCount);
    }

    #[Test]
    public function parseExpiryWithDays(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m): void {
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Reason', '30d'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNotNull($saved->getExpiresAt());
    }

    #[Test]
    public function parseExpiryWithHours(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Reason', '12h'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNotNull($saved->getExpiresAt());
    }

    #[Test]
    public function parseExpiryWithMinutes(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Reason', '60m'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNotNull($saved->getExpiresAt());
    }

    #[Test]
    public function addSetsBanOnChannel(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $akickRepo->method('save')->willReturnCallback(static function (): void {
        });

        $bans = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$bans): void {
            $bans[] = ['channel' => $channel, 'modes' => $modes, 'params' => $params];
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Spam'], $notifier, $translator));

        self::assertCount(1, $bans);
        self::assertSame('+b', $bans[0]['modes']);
        self::assertSame(['*!*@*.isp.com'], $bans[0]['params']);
    }

    #[Test]
    public function addWithEmptyExpiryCreatesPermanentAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Reason', ''], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNull($saved->getExpiresAt());
    }

    #[Test]
    public function channelNotRegisteredThrowsException(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        [$unused, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));

        $this->expectException(\App\Domain\ChanServ\Exception\ChannelNotRegisteredException::class);
        $cmd->execute($this->createContext($sender, $account, ['#unregistered', 'LIST'], $notifier, $translator));
    }

    #[Test]
    public function addKicksCurrentMembersMatchingAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);
        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });

        $kicked = [];
        $notifier->method('kickFromChannel')->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicked): void {
            $kicked[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('matches')->willReturn(true);
        $akick->method('getReason')->willReturn('Spam');
        $akick->method('getMask')->willReturn('*!*@*.isp.com');

        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 2, [
            ['uid' => 'UID2', 'roleLetter' => ''],
        ]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $badUserView = new SenderView('UID2', 'BadUser', 'bad', 'bad.isp.com', 'bad.isp.com', 'aaa', false, false, '001', 'bad.isp.com');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($badUserView);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'ADD', '*!*@*.isp.com', 'Spam'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $userLookup,
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup);
        $cmd->execute($context);

        self::assertCount(1, $kicked);
        self::assertSame('UID2', $kicked[0]['uid']);
        self::assertSame('akick.add.done', $messages[0]);
    }

    #[Test]
    public function addWithFounderNickInMaskRepliesProtectedUser(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $founderNick = $this->createStub(RegisteredNick::class);
        $founderNick->method('getNickname')->willReturn('Founder');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnMap([[1, $founderNick]]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('listByChannel')->willReturn([]);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'Founder!*@*'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.protected_user', $messages[0]);
        self::assertStringContainsString('Founder', $messages[0]);
    }

    #[Test]
    public function addWithWildcardNickMatchingFounderRepliesProtectedUser(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $founderNick = $this->createStub(RegisteredNick::class);
        $founderNick->method('getNickname')->willReturn('Alice');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnMap([[1, $founderNick]]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('listByChannel')->willReturn([]);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*Alice*!*@*.isp.com'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.protected_user', $messages[0]);
        self::assertStringContainsString('Alice', $messages[0]);
    }

    #[Test]
    public function addWithSuccessorNickInMaskRepliesProtectedUser(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(1);
        $channel->method('getSuccessorNickId')->willReturn(2);
        $channel->method('isFounder')->willReturnCallback(static fn (int $id): bool => 1 === $id);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $founderNick = $this->createStub(RegisteredNick::class);
        $founderNick->method('getNickname')->willReturn('Founder');
        $successorNick = $this->createStub(RegisteredNick::class);
        $successorNick->method('getNickname')->willReturn('Bob');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnMap([[1, $founderNick], [2, $successorNick]]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('listByChannel')->willReturn([]);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'Bob!*@isp.com'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.protected_user', $messages[0]);
        self::assertStringContainsString('Bob', $messages[0]);
    }

    #[Test]
    public function addWithAccessListUserNickInMaskRepliesProtectedUser(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $founderNick = $this->createStub(RegisteredNick::class);
        $founderNick->method('getNickname')->willReturn('Founder');

        $accessNick = $this->createStub(RegisteredNick::class);
        $accessNick->method('getNickname')->willReturn('Charley');

        $access = $this->createStub(\App\Domain\ChanServ\Entity\ChannelAccess::class);
        $access->method('getNickId')->willReturn(5);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnMap([[1, $founderNick], [5, $accessNick]]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('listByChannel')->willReturn([$access]);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'Charley!*@*'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('akick.protected_user', $messages[0]);
        self::assertStringContainsString('Charley', $messages[0]);
    }

    #[Test]
    public function addWithWildcardOnlyNickNotBlockingAnyUserSucceeds(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $founderNick = $this->createStub(RegisteredNick::class);
        $founderNick->method('getNickname')->willReturn('Founder');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnMap([[1, $founderNick]]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(0);
        $akickRepo->method('findByChannelAndMask')->willReturn(null);

        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
        });

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('listByChannel')->willReturn([]);

        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Spammer'], $notifier, $translator));

        self::assertSame(['akick.add.done'], $messages);
        self::assertNotNull($saved);
        self::assertSame('*!*@*.isp.com', $saved->getMask());
    }
}
