<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\Del;

use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\UseCase\Del\DelMemo;
use App\MemoServ\Application\UseCase\Del\DelMemoHandler;
use App\MemoServ\Application\UseCase\Del\DelMemoOutcome;
use App\MemoServ\Application\UseCase\Del\DelMemoResult;
use App\MemoServ\Domain\Entity\Memo;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DelMemoHandler::class)]
#[CoversClass(DelMemo::class)]
#[CoversClass(DelMemoResult::class)]
final class DelMemoHandlerTest extends TestCase
{
    private MemoChannelPort $channelPort;

    private MemoRepositoryInterface $memoRepository;

    protected function setUp(): void
    {
        $this->channelPort = $this->createStub(MemoChannelPort::class);
        $this->memoRepository = $this->createStub(MemoRepositoryInterface::class);
    }

    private function createHandler(): DelMemoHandler
    {
        return new DelMemoHandler($this->channelPort, $this->memoRepository);
    }

    #[Test]
    public function deletesNickMemoSuccessfully(): void
    {
        $memo = new Memo(1, null, 2, 'To delete', new DateTimeImmutable());
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNickAndIndex')->willReturn($memo);
        $memoRepo->expects(self::once())->method('delete')->with($memo);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DelMemo(1, null, 1));

        self::assertSame(DelMemoOutcome::Deleted, $result->outcome);
        self::assertSame(1, $result->index);
    }

    #[Test]
    public function returnsNotFoundWhenNickMemoDoesNotExist(): void
    {
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNickAndIndex')->willReturn(null);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DelMemo(1, null, 99));

        self::assertSame(DelMemoOutcome::NotFound, $result->outcome);
        self::assertSame(99, $result->index);
    }

    #[Test]
    public function deletesChannelMemoSuccessfully(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->expects(self::once())->method('canManageChannelMemos')->with(5, 1)->willReturn(true);
        $this->channelPort = $channelPort;

        $memo = new Memo(null, 5, 2, 'Channel memo', new DateTimeImmutable());
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetChannelAndIndex')->willReturn($memo);
        $memoRepo->expects(self::once())->method('delete')->with($memo);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DelMemo(1, '#ares', 1));

        self::assertSame(DelMemoOutcome::Deleted, $result->outcome);
        self::assertSame(1, $result->index);
    }

    #[Test]
    public function returnsChannelNotRegisteredWhenChannelNotFound(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(null);
        $this->channelPort = $channelPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new DelMemo(1, '#unknown', 1));

        self::assertSame(DelMemoOutcome::ChannelNotRegistered, $result->outcome);
        self::assertSame('#unknown', $result->channelName);
    }

    #[Test]
    public function returnsNotFoundWhenChannelMemoDoesNotExist(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('canManageChannelMemos')->willReturn(true);
        $this->channelPort = $channelPort;

        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetChannelAndIndex')->willReturn(null);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DelMemo(1, '#Ares', 99));

        self::assertSame(DelMemoOutcome::NotFound, $result->outcome);
        self::assertSame(99, $result->index);
    }

    #[Test]
    public function returnsAccessDeniedWithoutLoadingOrDeletingMemo(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->method('canManageChannelMemos')->willReturn(false);
        $this->channelPort = $channelPort;

        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::never())->method('findByTargetChannelAndIndex');
        $memoRepository->expects(self::never())->method('delete');
        $this->memoRepository = $memoRepository;

        $result = $this->createHandler()->handle(new DelMemo(1, '#ares', 1));

        self::assertSame(DelMemoOutcome::AccessDenied, $result->outcome);
        self::assertSame('#Ares', $result->channelName);
    }
}
