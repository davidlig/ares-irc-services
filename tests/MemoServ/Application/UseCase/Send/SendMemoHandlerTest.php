<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\Send;

use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoThrottlePort;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\Send\SendMemo;
use App\MemoServ\Application\UseCase\Send\SendMemoHandler;
use App\MemoServ\Application\UseCase\Send\SendMemoOutcome;
use App\MemoServ\Application\UseCase\Send\SendMemoResult;
use App\MemoServ\Domain\Entity\Memo;
use App\MemoServ\Domain\Entity\MemoIgnore;
use App\MemoServ\Domain\Exception\MemoDisabledException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SendMemoHandler::class)]
#[CoversClass(SendMemo::class)]
#[CoversClass(SendMemoResult::class)]
final class SendMemoHandlerTest extends TestCase
{
    private MemoUserAccountPort $userAccountPort;

    private MemoChannelPort $channelPort;

    private MemoRepositoryInterface $memoRepository;

    private MemoIgnoreRepositoryInterface $memoIgnoreRepository;

    private MemoSettingsRepositoryInterface $memoSettingsRepository;

    private MemoThrottlePort $throttle;

    protected function setUp(): void
    {
        $this->userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $this->channelPort = $this->createStub(MemoChannelPort::class);
        $this->memoRepository = $this->createStub(MemoRepositoryInterface::class);
        $this->memoIgnoreRepository = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $this->memoSettingsRepository = $this->createStub(MemoSettingsRepositoryInterface::class);
        $this->throttle = $this->createStub(MemoThrottlePort::class);
    }

    private function createHandler(): SendMemoHandler
    {
        return new SendMemoHandler(
            $this->userAccountPort,
            $this->channelPort,
            $this->memoRepository,
            $this->memoIgnoreRepository,
            $this->memoSettingsRepository,
            $this->throttle,
            maxMemosPerNick: 10,
            maxMemosPerChannel: 20,
            sendMinIntervalSeconds: 30,
        );
    }

    #[Test]
    public function returnsThrottledWhenCooldownRemaining(): void
    {
        $throttle = $this->createStub(MemoThrottlePort::class);
        $throttle->method('getRemainingCooldownSeconds')->willReturn(15);
        $this->throttle = $throttle;

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, 'Bob', 'Hello', new DateTimeImmutable()));

        self::assertSame(SendMemoOutcome::Throttled, $result->outcome);
        self::assertSame(15, $result->cooldownRemainingSeconds);
    }

    #[Test]
    public function sendsToNickSuccessfully(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-07 10:00:00 UTC');
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'es'));
        $this->userAccountPort = $userAccountPort;

        $memoSettings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $memoSettings->method('isEnabledForNick')->willReturn(true);
        $this->memoSettingsRepository = $memoSettings;

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetNick')->willReturn(2);
        $memoRepo->method('countUnreadByTargetNick')->willReturn(1);
        $memoRepo->expects(self::once())->method('save')->with(self::callback(
            static fn (Memo $memo): bool => 2 === $memo->getTargetNickId()
                && null === $memo->getTargetChannelId()
                && 1 === $memo->getSenderNickId()
                && 'Hello Bob' === $memo->getMessage()
                && $occurredAt === $memo->getCreatedAt(),
        ));
        $this->memoRepository = $memoRepo;

        $throttle = $this->createMock(MemoThrottlePort::class);
        $throttle->method('getRemainingCooldownSeconds')->willReturn(0);
        $throttle->expects(self::once())->method('recordSend')->with('UID1');
        $this->throttle = $throttle;

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, 'Bob', 'Hello Bob', $occurredAt));

        self::assertSame(SendMemoOutcome::SentToNick, $result->outcome);
        self::assertSame('Bob', $result->targetName);
        self::assertSame(2, $result->targetNickId);
        self::assertSame(1, $result->unreadCount);
        self::assertSame('es', $result->recipientLanguage);
    }

    #[Test]
    public function sendToNickReturnsNickNotRegistered(): void
    {
        $this->userAccountPort = $this->createStub(MemoUserAccountPort::class);

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, 'UnknownNick', 'Hello', new DateTimeImmutable()));

        self::assertSame(SendMemoOutcome::NickNotRegistered, $result->outcome);
        self::assertSame('UnknownNick', $result->targetName);
    }

    #[Test]
    public function sendToNickReturnsCannotSendToSelf(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(1, 'Alice', 'en'));
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, 'Alice', 'Hello', new DateTimeImmutable()));

        self::assertSame(SendMemoOutcome::CannotSendToSelf, $result->outcome);
    }

    #[Test]
    public function sendToNickThrowsMemoDisabledExceptionWhenMemosDisabled(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoSettings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $memoSettings->method('isEnabledForNick')->willReturn(false);
        $this->memoSettingsRepository = $memoSettings;

        $this->expectException(MemoDisabledException::class);

        $handler = $this->createHandler();
        $handler->handle(new SendMemo('UID1', 1, 'Bob', 'Hello', new DateTimeImmutable()));
    }

    #[Test]
    public function sendToNickReturnsIgnoredWhenRecipientIgnoresSender(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoSettings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $memoSettings->method('isEnabledForNick')->willReturn(true);
        $this->memoSettingsRepository = $memoSettings;

        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetNickAndIgnored')->willReturn($this->createStub(MemoIgnore::class));
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, 'Bob', 'Hello', new DateTimeImmutable()));

        self::assertSame(SendMemoOutcome::Ignored, $result->outcome);
    }

    #[Test]
    public function sendToNickReturnsLimitReachedWhenMaxReached(): void
    {
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findAccountByNick')->willReturn(new MemoAccountView(2, 'Bob', 'en'));
        $this->userAccountPort = $userAccountPort;

        $memoSettings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $memoSettings->method('isEnabledForNick')->willReturn(true);
        $this->memoSettingsRepository = $memoSettings;

        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetNick')->willReturn(10);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, 'Bob', 'Hello', new DateTimeImmutable()));

        self::assertSame(SendMemoOutcome::LimitReached, $result->outcome);
        self::assertSame('Bob', $result->targetName);
    }

    #[Test]
    public function sendsToChannelSuccessfully(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-07 10:00:00 UTC');
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $this->channelPort = $channelPort;

        $memoSettings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $memoSettings->method('isEnabledForChannel')->willReturn(true);
        $this->memoSettingsRepository = $memoSettings;

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetChannel')->willReturn(2);
        $memoRepo->expects(self::once())->method('save')->with(self::callback(
            static fn (Memo $memo): bool => null === $memo->getTargetNickId()
                && 5 === $memo->getTargetChannelId()
                && 1 === $memo->getSenderNickId()
                && 'Hello channel' === $memo->getMessage()
                && $occurredAt === $memo->getCreatedAt(),
        ));
        $this->memoRepository = $memoRepo;

        $throttle = $this->createMock(MemoThrottlePort::class);
        $throttle->method('getRemainingCooldownSeconds')->willReturn(0);
        $throttle->expects(self::once())->method('recordSend')->with('UID1');
        $this->throttle = $throttle;

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, '#Ares', 'Hello channel', $occurredAt));

        self::assertSame(SendMemoOutcome::SentToChannel, $result->outcome);
        self::assertSame('#Ares', $result->targetName);
    }

    #[Test]
    public function sendToChannelReturnsChannelNotRegistered(): void
    {
        $this->channelPort = $this->createStub(MemoChannelPort::class);

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, '#unknown', 'Hello', new DateTimeImmutable()));

        self::assertSame(SendMemoOutcome::ChannelNotRegistered, $result->outcome);
        self::assertSame('#unknown', $result->targetName);
    }

    #[Test]
    public function sendToChannelThrowsMemoDisabledExceptionWhenMemosDisabled(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $this->channelPort = $channelPort;

        $memoSettings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $memoSettings->method('isEnabledForChannel')->willReturn(false);
        $this->memoSettingsRepository = $memoSettings;

        $this->expectException(MemoDisabledException::class);

        $handler = $this->createHandler();
        $handler->handle(new SendMemo('UID1', 1, '#Ares', 'Hello', new DateTimeImmutable()));
    }

    #[Test]
    public function sendToChannelReturnsIgnoredWhenChannelIgnoresSender(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $this->channelPort = $channelPort;

        $memoSettings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $memoSettings->method('isEnabledForChannel')->willReturn(true);
        $this->memoSettingsRepository = $memoSettings;

        $memoIgnore = $this->createStub(MemoIgnoreRepositoryInterface::class);
        $memoIgnore->method('findByTargetChannelAndIgnored')->willReturn($this->createStub(MemoIgnore::class));
        $this->memoIgnoreRepository = $memoIgnore;

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, '#Ares', 'Hello', new DateTimeImmutable()));

        self::assertSame(SendMemoOutcome::Ignored, $result->outcome);
    }

    #[Test]
    public function sendToChannelReturnsLimitReachedWhenMaxReached(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $this->channelPort = $channelPort;

        $memoSettings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $memoSettings->method('isEnabledForChannel')->willReturn(true);
        $this->memoSettingsRepository = $memoSettings;

        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('countByTargetChannel')->willReturn(20);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new SendMemo('UID1', 1, '#Ares', 'Hello', new DateTimeImmutable()));

        self::assertSame(SendMemoOutcome::LimitReached, $result->outcome);
        self::assertSame('#Ares', $result->targetName);
    }
}
