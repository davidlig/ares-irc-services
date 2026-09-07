<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\List;

use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\List\ListMemos;
use App\MemoServ\Application\UseCase\List\ListMemosHandler;
use App\MemoServ\Application\UseCase\List\ListMemosOutcome;
use App\MemoServ\Application\UseCase\List\ListMemosResult;
use App\MemoServ\Domain\Entity\Memo;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListMemosHandler::class)]
#[CoversClass(ListMemos::class)]
#[CoversClass(ListMemosResult::class)]
final class ListMemosHandlerTest extends TestCase
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

    private function createHandler(): ListMemosHandler
    {
        return new ListMemosHandler($this->userAccountPort, $this->channelPort, $this->memoRepository);
    }

    #[Test]
    public function listsNickMemosSuccessfully(): void
    {
        $memo1 = new Memo(1, null, 2, 'First memo', new DateTimeImmutable('-1 hour'));
        $memo2 = new Memo(1, null, 3, str_repeat('a', 60), new DateTimeImmutable('-30 minutes'));
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn([$memo1, $memo2]);
        $this->memoRepository = $memoRepo;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findNicknameById')->willReturnMap([
            [2, 'Bob'],
            [3, 'Charlie'],
        ]);
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new ListMemos(1, 'Alice'));

        self::assertSame(ListMemosOutcome::Success, $result->outcome);
        self::assertSame('Alice', $result->targetLabel);
        self::assertCount(2, $result->items);
        self::assertSame(1, $result->items[0]->index);
        self::assertSame('Bob', $result->items[0]->senderDisplay);
        self::assertSame('First memo', $result->items[0]->preview);
        self::assertSame(2, $result->items[1]->index);
        self::assertSame('Charlie', $result->items[1]->senderDisplay);
        self::assertStringEndsWith('…', $result->items[1]->preview);
    }

    #[Test]
    public function listsNickMemosUsesIdFallbackWhenNicknameNotFound(): void
    {
        $memo = new Memo(1, null, 99, 'Test');
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn([$memo]);
        $this->memoRepository = $memoRepo;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findNicknameById')->willReturn(null);
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new ListMemos(1, 'Alice'));

        self::assertSame(ListMemosOutcome::Success, $result->outcome);
        self::assertSame('99', $result->items[0]->senderDisplay);
    }

    #[Test]
    public function returnsEmptyWhenNoNickMemos(): void
    {
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetNick')->willReturn([]);
        $this->memoRepository = $memoRepo;

        $handler = $this->createHandler();
        $result = $handler->handle(new ListMemos(1, 'Alice'));

        self::assertSame(ListMemosOutcome::Empty, $result->outcome);
        self::assertSame('Alice', $result->targetLabel);
    }

    #[Test]
    public function listsChannelMemosSuccessfully(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(new MemoChannelView(5, '#Ares'));
        $channelPort->expects(self::once())->method('requireReadAccess')->with(5, 1, '#ares', 'LIST');
        $this->channelPort = $channelPort;

        $memo = new Memo(null, 5, 2, 'Channel message');
        $memoRepo = $this->createStub(MemoRepositoryInterface::class);
        $memoRepo->method('findByTargetChannel')->willReturn([$memo]);
        $this->memoRepository = $memoRepo;

        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('findNicknameById')->willReturn('Bob');
        $this->userAccountPort = $userAccountPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new ListMemos(1, 'Alice', '#ares'));

        self::assertSame(ListMemosOutcome::Success, $result->outcome);
        self::assertSame('#ares', $result->targetLabel);
        self::assertCount(1, $result->items);
    }

    #[Test]
    public function returnsChannelNotRegisteredWhenChannelNotFound(): void
    {
        $channelPort = $this->createStub(MemoChannelPort::class);
        $channelPort->method('findChannelByName')->willReturn(null);
        $this->channelPort = $channelPort;

        $handler = $this->createHandler();
        $result = $handler->handle(new ListMemos(1, 'Alice', '#unknown'));

        self::assertSame(ListMemosOutcome::ChannelNotRegistered, $result->outcome);
        self::assertSame('#unknown', $result->channelName);
    }
}
