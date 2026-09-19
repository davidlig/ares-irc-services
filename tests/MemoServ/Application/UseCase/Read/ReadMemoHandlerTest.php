<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\Read;

use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\Read\ReadMemo;
use App\MemoServ\Application\UseCase\Read\ReadMemoHandler;
use App\MemoServ\Application\UseCase\Read\ReadMemoOutcome;
use App\MemoServ\Application\UseCase\Read\ReadMemoResult;
use App\MemoServ\Domain\Entity\Memo;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReadMemoHandler::class)]
#[CoversClass(ReadMemo::class)]
#[CoversClass(ReadMemoResult::class)]
final class ReadMemoHandlerTest extends TestCase
{
    private MemoUserAccountPort $userAccountPort;

    private MemoChannelPort $channelPort;

    private MemoRepositoryInterface $memoRepository;

    protected function setUp(): void
    {
        $this->userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $this->channelPort = $this->createStub(MemoChannelPort::class);
        $this->memoRepository = $this->createStub(MemoRepositoryInterface::class);
    }

    private function createHandler(): ReadMemoHandler
    {
        return new ReadMemoHandler($this->userAccountPort, $this->channelPort, $this->memoRepository);
    }

    #[Test]
    public function readsNickMemoSuccessfully(): void
    {
        $createdAt = new DateTimeImmutable('2026-09-07 10:00:00 UTC');
        $readAt = new DateTimeImmutable('2026-09-07 11:00:00 UTC');
        $memo = new Memo(1, null, 2, 'Secret message', $createdAt);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNickAndIndex')->willReturn($memo);
        $memoRepo->expects(self::once())->method('save')->with($memo);
        $this->memoRepository = $memoRepo;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findNicknameById')->willReturn('Bob');
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new ReadMemo(1, null, 1, $readAt));

        self::assertSame(ReadMemoOutcome::Success, $result->outcome);
        self::assertSame(1, $result->index);
        self::assertSame('Bob', $result->from);
        self::assertSame('Secret message', $result->message);
        self::assertTrue($memo->isRead());
        self::assertSame($readAt, $memo->getReadAt());
        self::assertSame($createdAt, $result->createdAt);
    }

    #[Test]
    public function readsNickMemoUsesIdFallbackWhenNicknameNotFound(): void
    {
        $memo = new Memo(1, null, 42, 'Secret message', new DateTimeImmutable());
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNickAndIndex')->willReturn($memo);
        $this->memoRepository = $memoRepo;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findNicknameById')->willReturn(null);
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new ReadMemo(1, null, 1, new DateTimeImmutable()));

        self::assertSame(ReadMemoOutcome::Success, $result->outcome);
        self::assertSame('42', $result->from);
    }

    #[Test]
    public function returnsNotFoundWhenNickMemoDoesNotExist(): void
    {
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNickAndIndex')->willReturn(null);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new ReadMemo(1, null, 99, new DateTimeImmutable()));

        self::assertSame(ReadMemoOutcome::NotFound, $result->outcome);
        self::assertSame(99, $result->index);
    }

    #[Test]
    public function readsChannelMemoSuccessfully(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->expects(self::once())->method('canReadChannelMemos')->with(5, 1)->willReturn(true);
        $this->channelPort = $channelPort;

        $memo = new Memo(null, 5, 2, 'Channel message', new DateTimeImmutable());
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetChannelAndIndex')->willReturn($memo);
        $memoRepo->expects(self::once())->method('save')->with($memo);
        $this->memoRepository = $memoRepo;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findNicknameById')->willReturn('Bob');
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new ReadMemo(1, '#ares', 1, new DateTimeImmutable()));

        self::assertSame(ReadMemoOutcome::Success, $result->outcome);
        self::assertSame('Bob', $result->from);
        self::assertSame('Channel message', $result->message);
    }

    #[Test]
    public function returnsChannelNotRegisteredWhenChannelNotFound(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(null);
        $this->channelPort = $channelPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new ReadMemo(1, '#unknown', 1, new DateTimeImmutable()));

        self::assertSame(ReadMemoOutcome::ChannelNotRegistered, $result->outcome);
        self::assertSame('#unknown', $result->channelName);
    }

    #[Test]
    public function returnsNotFoundWhenChannelMemoDoesNotExist(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('canReadChannelMemos')->willReturn(true);
        $this->channelPort = $channelPort;

        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetChannelAndIndex')->willReturn(null);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new ReadMemo(1, '#Ares', 99, new DateTimeImmutable()));

        self::assertSame(ReadMemoOutcome::NotFound, $result->outcome);
        self::assertSame(99, $result->index);
    }

    #[Test]
    public function returnsAccessDeniedWithoutLoadingOrMutatingMemo(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('canReadChannelMemos')->willReturn(false);
        $this->channelPort = $channelPort;

        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::never())->method('findByTargetChannelAndIndex');
        $memoRepository->expects(self::never())->method('save');
        $this->memoRepository = $memoRepository;

        $result = $this->createHandler()->handle(new ReadMemo(1, '#ares', 1, new DateTimeImmutable()));

        self::assertSame(ReadMemoOutcome::AccessDenied, $result->outcome);
        self::assertSame('#Ares', $result->channelName);
    }
}
