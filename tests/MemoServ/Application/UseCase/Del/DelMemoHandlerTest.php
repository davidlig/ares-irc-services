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

    #[Test]
    public function deletesOnlyPersonalInboxForAll(): void
    {
        $memos = [new Memo(1, null, 2, 'One', new DateTimeImmutable()), new Memo(1, null, 3, 'Two', new DateTimeImmutable())];
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::once())->method('findByTargetNick')->with(1)->willReturn($memos);
        $repository->expects(self::never())->method('deleteAllForNick');
        $deleted = [];
        $repository->expects(self::exactly(2))->method('delete')->willReturnCallback(static function (Memo $memo) use (&$deleted): void { $deleted[] = $memo; });
        $this->memoRepository = $repository;
        $result = $this->createHandler()->handle(new DelMemo(1, null, 0, all: true));
        self::assertSame(DelMemoOutcome::AllDeleted, $result->outcome);
        self::assertSame($memos, $deleted);
    }

    #[Test]
    public function handlesAllForChannelAndEmptyInboxes(): void
    {
        $channel = $this->createStub(MemoChannelPort::class);
        $channel->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channel->method('canManageChannelMemos')->willReturn(true);
        $this->channelPort = $channel;
        $memo = new Memo(null, 5, 2, 'One', new DateTimeImmutable());
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::exactly(2))->method('findByTargetChannel')->with(5)->willReturnOnConsecutiveCalls([$memo], []);
        $repository->method('findByTargetNick')->willReturn([]);
        $repository->expects(self::once())->method('delete')->with($memo);
        $this->memoRepository = $repository;
        $deleted = $this->createHandler()->handle(new DelMemo(1, '#ares', 0, all: true));
        $emptyChannel = $this->createHandler()->handle(new DelMemo(1, '#ares', 0, all: true));
        $emptyNick = $this->createHandler()->handle(new DelMemo(1, null, 0, all: true));
        self::assertSame(DelMemoOutcome::AllDeleted, $deleted->outcome);
        self::assertSame(DelMemoOutcome::Empty, $emptyChannel->outcome);
        self::assertSame(DelMemoOutcome::Empty, $emptyNick->outcome);
    }

    #[Test]
    public function deniesAllBeforeReadingChannelInbox(): void
    {
        $channel = $this->createStub(MemoChannelPort::class);
        $channel->method('findChannelByName')->willReturnOnConsecutiveCalls(new MemoChannelView(5, '#Ares'), null);
        $channel->method('canManageChannelMemos')->willReturn(false);
        $this->channelPort = $channel;
        $repository = $this->createMock(MemoRepositoryInterface::class);
        $repository->expects(self::never())->method('findByTargetChannel');
        $repository->expects(self::never())->method('delete');
        $this->memoRepository = $repository;
        self::assertSame(DelMemoOutcome::AccessDenied, $this->createHandler()->handle(new DelMemo(1, '#ares', 0, all: true))->outcome);
        self::assertSame(DelMemoOutcome::ChannelNotRegistered, $this->createHandler()->handle(new DelMemo(1, '#ares', 0, all: true))->outcome);
    }

    protected function setUp(): void
    {
        $this->channelPort = $this->createStub(MemoChannelPort::class);
        $this->memoRepository = $this->createStub(MemoRepositoryInterface::class);
    }

    private function createHandler(): DelMemoHandler
    {
        return new DelMemoHandler($this->channelPort, $this->memoRepository);
    }
}
