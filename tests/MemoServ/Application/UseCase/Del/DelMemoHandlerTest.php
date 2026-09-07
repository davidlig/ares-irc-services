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
        $memo = new Memo(1, null, 2, 'To delete');
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
        $channelPort->expects(self::once())->method('requireManageAccess')->with(5, 1, '#ares', 'DEL');
        $this->channelPort = $channelPort;

        $memo = new Memo(null, 5, 2, 'Channel memo');
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
        $this->channelPort = $channelPort;

        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetChannelAndIndex')->willReturn(null);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new DelMemo(1, '#Ares', 99));

        self::assertSame(DelMemoOutcome::NotFound, $result->outcome);
        self::assertSame(99, $result->index);
    }
}
