<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\AkickCommand;
use App\Application\Port\BurstCompletePort;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelAccess;
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
            $this->createServiceNicks(),
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '0', 'Spammer'], $notifier, $translator));

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
        $existingAkick->method('isExpired')->willReturn(false);
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
    public function addReplacesExpiredAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $expiredAkick = $this->createStub(ChannelAkick::class);
        $expiredAkick->method('isExpired')->willReturn(true);
        $expiredAkick->method('getMask')->willReturn('*!*@*.isp.com');

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('countByChannel')->willReturn(1);
        $akickRepo->method('findByChannelAndMask')->willReturn($expiredAkick);

        $removed = false;
        $akickRepo->method('remove')->willReturnCallback(static function () use (&$removed): void {
            $removed = true;
        });

        /** @var ChannelAkick|null $saved */
        $saved = null;
        $akickRepo->method('save')->willReturnCallback(static function (ChannelAkick $entity) use (&$saved): void {
            $saved = $entity;
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
        $notifier->method('setChannelModes')->willReturnCallback(static function (): void {
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '0', 'New reason'], $notifier, $translator));

        self::assertTrue($removed, 'Expired AKICK should be removed');
        self::assertNotNull($saved, 'New AKICK should be saved');
        self::assertSame('*!*@*.isp.com', $saved->getMask());
        self::assertSame('New reason', $saved->getReason());
        self::assertSame(['akick.add.done'], $messages);
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
        self::assertStringContainsString('"%index%":"1"', $messages[1], 'Entry should contain index 1');
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '0', 'Spammer'], $notifier, $translator));

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
        self::assertCount(3, $cmd->getSubCommandHelp());
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
    public function listWithUnknownCreatorShowsUnknown(): void
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
        self::assertStringContainsString('akick.list.unknown_creator', $messages[1]);
    }

    #[Test]
    public function listWithNullCreatorNickIdShowsUnknown(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getMask')->willReturn('*!*@test.com');
        $akick->method('getReason')->willReturn('Test');
        $akick->method('getCreatorNickId')->willReturn(null);
        $akick->method('getExpiresAt')->willReturn(null);

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

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'LIST'], $notifier, $translator));

        self::assertCount(2, $messages);
        self::assertStringContainsString('akick.list.unknown_creator', $messages[1]);
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '0', 'Reason'], $notifier, $translator));

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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '0', 'Reason'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNull($saved->getExpiresAt());
    }

    #[Test]
    public function addWithInvalidExpiryFormatReturnsSyntaxError(): void
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

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $messages = [];
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'invalid', 'Reason'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function addWithOnlyReasonReturnsSyntaxError(): void
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

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $messages = [];
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'Spam', 'bot', 'detected'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function addWithExpiryAndReasonSucceeds(): void
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '7d', 'Spam', 'bot', 'detected'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNotNull($saved->getExpiresAt(), 'Expiry should be set');
        self::assertSame('Spam bot detected', $saved->getReason(), 'Multi-word reason should be joined');
    }

    #[Test]
    public function addWithEmptyReasonStringSetsNull(): void
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '   '], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNull($saved->getExpiresAt());
        self::assertNull($saved->getReason(), 'Whitespace-only reason should be null');
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '30d', 'Reason'], $notifier, $translator));

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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '12h', 'Reason'], $notifier, $translator));

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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '60m', 'Reason'], $notifier, $translator));

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

        $kicks = [];
        $notifier->method('kickFromChannel')->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicks): void {
            $kicks[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $burstComplete = $this->createStub(BurstCompletePort::class);
        $burstComplete->method('isComplete')->willReturn(true);

        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 1, [['uid' => 'UID2', 'roleLetter' => '']]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $badUserView = new SenderView('UID2', 'BadUser', 'bad', 'bad.isp.com', 'bad.isp.com', 'aaa', false, false, '001', 'bad.isp.com');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($badUserView);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'ADD', '*!*@*.isp.com', '0', 'Spam'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $userLookup,
            $this->createServiceNicks(),
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup, $burstComplete);
        $cmd->execute($context);

        self::assertCount(1, $bans);
        self::assertSame('+b', $bans[0]['modes']);
        self::assertSame(['*!*@*.isp.com'], $bans[0]['params']);
        self::assertCount(1, $kicks);
        self::assertSame('UID2', $kicks[0]['uid']);
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '', 'Reason'], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNull($saved->getExpiresAt());
    }

    #[Test]
    public function addWithExpiryAndEmptyReasonSetsReasonToNull(): void
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '7d', '   '], $notifier, $translator));

        self::assertNotNull($saved);
        self::assertNull($saved->getReason());
        self::assertNotNull($saved->getExpiresAt());
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
    public function addAppliesBanAndKicksMatchingChannelMembers(): void
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

        $bans = [];
        $notifier->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$bans): void {
            $bans[] = ['channel' => $channel, 'modes' => $modes, 'params' => $params];
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {
        });

        $kicks = [];
        $notifier->method('kickFromChannel')->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicks): void {
            $kicks[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $channelView = new \App\Application\Port\ChannelView('#test', '', null, 2, [
            ['uid' => 'UID2', 'roleLetter' => ''],
        ]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $badUserView = new SenderView('UID2', 'BadUser', 'bad', 'bad.isp.com', 'bad.isp.com', 'aaa', false, false, '001', 'bad.isp.com');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($badUserView);

        $burstComplete = $this->createStub(BurstCompletePort::class);
        $burstComplete->method('isComplete')->willReturn(true);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'ADD', '*!*@*.isp.com', '0', 'Spam'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $userLookup,
            $this->createServiceNicks(),
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup, $burstComplete);
        $cmd->execute($context);

        self::assertCount(1, $bans);
        self::assertSame('+b', $bans[0]['modes']);
        self::assertSame(['*!*@*.isp.com'], $bans[0]['params']);
        self::assertCount(1, $kicks);
        self::assertSame('UID2', $kicks[0]['uid']);
        self::assertSame('Spam', $kicks[0]['reason']);
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

        $access = $this->createStub(ChannelAccess::class);
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', '0', 'Spammer'], $notifier, $translator));

        self::assertSame(['akick.add.done'], $messages);
        self::assertNotNull($saved);
        self::assertSame('*!*@*.isp.com', $saved->getMask());
    }

    #[Test]
    public function addWithSpecificNickNotMatchingProtectedUserSucceeds(): void
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
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', 'SpammerBot!*@*.evil.com', '0', 'Bad bot'], $notifier, $translator));

        self::assertSame(['akick.add.done'], $messages);
        self::assertNotNull($saved);
        self::assertSame('SpammerBot!*@*.evil.com', $saved->getMask());
        self::assertSame('Bad bot', $saved->getReason());
    }

    #[Test]
    public function addWithReasonButNoExpiryReturnsSyntaxError(): void
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
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));
        $cmd->execute($this->createContext($sender, $account, ['#test', 'ADD', '*!*@*.isp.com', 'NotAnExpiry'], $notifier, $translator));

        self::assertCount(1, $messages);
        self::assertStringContainsString('error.syntax', $messages[0]);
    }

    #[Test]
    public function listHandlesRaceConditionWhereAkicksDisappearBetweenChecks(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(450);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn($access);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getMask')->willReturn('*@test.com');
        $akick->method('getCreatorNickId')->willReturn(1);

        $creatorNick = $this->createStub(RegisteredNick::class);
        $creatorNick->method('getNickname')->willReturn('TestNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($creatorNick);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturnOnConsecutiveCalls([$akick], []);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $bans = [];
        $notifier->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$bans): void {
            $bans[] = ['channel' => $channel, 'modes' => $modes, 'params' => $params];
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelView = new \App\Application\Port\ChannelView('#test', '+nt', null, 0, []);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $burstComplete = $this->createStub(BurstCompletePort::class);
        $burstComplete->method('isComplete')->willReturn(true);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'LIST'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup, $burstComplete);
        $cmd->execute($context);

        self::assertCount(2, $messages);
        self::assertStringContainsString('akick.list.header', $messages[0]);
        self::assertStringContainsString('akick.list.entry', $messages[1]);
        self::assertEmpty($bans, 'No bans should be applied when AKICKs disappear between checks');
    }

    #[Test]
    public function listWhenChannelViewNotFoundSkipsAkickEnforcement(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getMask')->willReturn('*!*@*.isp.com');
        $akick->method('getReason')->willReturn('Test');
        $akick->method('getCreatorNickId')->willReturn(1);
        $akick->method('getExpiresAt')->willReturn(null);

        $creatorNick = $this->createStub(RegisteredNick::class);
        $creatorNick->method('getNickname')->willReturn('TestNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($creatorNick);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $bans = [];
        $notifier->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$bans): void {
            $bans[] = ['channel' => $channel, 'modes' => $modes, 'params' => $params];
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(null);

        $burstComplete = $this->createStub(BurstCompletePort::class);
        $burstComplete->method('isComplete')->willReturn(true);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'LIST'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup, $burstComplete);
        $cmd->execute($context);

        self::assertCount(2, $messages);
        self::assertStringContainsString('akick.list.header', $messages[0]);
        self::assertEmpty($bans, 'No bans should be applied when channel view is null');
    }

    #[Test]
    public function listAppliesBansToMatchingChannelMembers(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getMask')->willReturn('*!*@*.isp.com');
        $akick->method('getReason')->willReturn('Spam');
        $akick->method('getCreatorNickId')->willReturn(1);
        $akick->method('getExpiresAt')->willReturn(null);
        $akick->method('matches')->willReturnCallback(static fn (string $mask): bool => str_contains($mask, '.isp.com'));

        $creatorNick = $this->createStub(RegisteredNick::class);
        $creatorNick->method('getNickname')->willReturn('TestNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($creatorNick);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $bans = [];
        $notifier->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$bans): void {
            $bans[] = ['channel' => $channel, 'modes' => $modes, 'params' => $params];
        });

        $kicks = [];
        $notifier->method('kickFromChannel')->willReturnCallback(static function (string $channel, string $uid, string $reason) use (&$kicks): void {
            $kicks[] = ['channel' => $channel, 'uid' => $uid, 'reason' => $reason];
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelView = new \App\Application\Port\ChannelView('#test', '+nt', null, 2, [
            ['uid' => 'UID2', 'roleLetter' => ''],
        ]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $badUser = new SenderView('UID2', 'BadUser', 'user', 'bad.isp.com', 'bad.isp.com', '192.168.1.1', false, false, '001', 'bad.isp.com');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($badUser);

        $burstComplete = $this->createStub(BurstCompletePort::class);
        $burstComplete->method('isComplete')->willReturn(true);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'LIST'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $userLookup,
            $this->createServiceNicks(),
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup, $burstComplete);
        $cmd->execute($context);

        self::assertCount(2, $messages);
        self::assertCount(1, $bans, 'Should apply ban for matching AKICK');
        self::assertSame('+b', $bans[0]['modes']);
        self::assertCount(1, $kicks, 'Should kick matching user');
        self::assertSame('UID2', $kicks[0]['uid']);
    }

    #[Test]
    public function listSkipsUnknownUsersEnforcingAkick(): void
    {
        $sender = new SenderView('UID1', 'Founder', 'i', 'h', 'c', 'ip');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $channelRepo = $this->createChannelMock(1, 1);

        $akick = $this->createStub(ChannelAkick::class);
        $akick->method('getMask')->willReturn('*!*@*');
        $akick->method('getReason')->willReturn('Test');
        $akick->method('getCreatorNickId')->willReturn(1);
        $akick->method('getExpiresAt')->willReturn(null);
        $akick->method('matches')->willReturn(true);

        $creatorNick = $this->createStub(RegisteredNick::class);
        $creatorNick->method('getNickname')->willReturn('TestNick');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($creatorNick);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akick]);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $levelRepo = $this->createStub(ChannelLevelRepositoryInterface::class);
        $accessHelper = new ChanServAccessHelper($accessRepo, $levelRepo);

        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $bans = [];
        $notifier->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modes, array $params) use (&$bans): void {
            $bans[] = ['channel' => $channel, 'modes' => $modes, 'params' => $params];
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $channelView = new \App\Application\Port\ChannelView('#test', '+nt', null, 2, [
            ['uid' => 'UID2', 'roleLetter' => ''],
        ]);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($channelView);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $burstComplete = $this->createStub(BurstCompletePort::class);
        $burstComplete->method('isComplete')->willReturn(true);

        $context = new ChanServContext(
            $sender,
            $account,
            'AKICK',
            ['#test', 'LIST'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $userLookup,
            $this->createServiceNicks(),
        );

        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $channelLookup, $burstComplete);
        $cmd->execute($context);

        self::assertCount(2, $messages);
        self::assertEmpty($bans, 'No bans should be applied when user is unknown');
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        [$channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();
        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));

        self::assertFalse($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        [$channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper] = $this->createStubReposAndHelper();
        $cmd = new AkickCommand($channelRepo, $akickRepo, $nickRepo, $accessRepo, $accessHelper, $this->createStub(ChannelLookupPort::class));

        self::assertFalse($cmd->allowsForbiddenChannel());
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
