<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\GetPendingNickNotice;

use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\BurstMemoNoticeCache;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNotice;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeHandler;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeOutcome;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(GetPendingNickNotice::class)]
#[CoversClass(GetPendingNickNoticeHandler::class)]
#[CoversClass(GetPendingNickNoticeResult::class)]
#[CoversClass(BurstMemoNoticeCache::class)]
final class GetPendingNickNoticeHandlerTest extends TestCase
{
    #[Test]
    public function returnsNoNoticeWhenThereAreNoUnreadMemos(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('countUnreadByTargetNick')->with(10)->willReturn(0);
        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::never())->method('getLanguage');

        $handler = new GetPendingNickNoticeHandler($memoRepository, $userAccountPort);

        $result = $handler->handle(new GetPendingNickNotice(10, '001ABC'));

        self::assertSame(GetPendingNickNoticeOutcome::NoNotice, $result->outcome);
        self::assertSame('', $result->uid);
        self::assertSame(0, $result->unreadCount);
        self::assertSame('', $result->language);
    }

    #[Test]
    public function returnsSemanticNoticeDataForUnreadMemos(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('countUnreadByTargetNick')->with(10)->willReturn(3);
        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())->method('getLanguage')->with(10)->willReturn('es');

        $handler = new GetPendingNickNoticeHandler($memoRepository, $userAccountPort);

        $result = $handler->handle(new GetPendingNickNotice(10, '001ABC'));

        self::assertSame(GetPendingNickNoticeOutcome::PendingMemos, $result->outcome);
        self::assertSame('001ABC', $result->uid);
        self::assertSame(3, $result->unreadCount);
        self::assertSame('es', $result->language);
    }

    #[Test]
    public function usesPrefetchedCountsAndRefreshesInvalidatedBatchAsAGroup(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::never())->method('countUnreadByTargetNick');
        $memoRepository->expects(self::once())->method('countUnreadByTargetNickIds')->with([10, 11])->willReturn([10 => 1, 11 => 0]);
        $cache = new BurstMemoNoticeCache();
        $cache->beginBatch([10, 11], [10 => 2], new stdClass());
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('getLanguage')->willReturn('en');
        $handler = new GetPendingNickNoticeHandler($memoRepository, $userAccountPort, $cache);

        self::assertSame(2, $handler->handle(new GetPendingNickNotice(10, '001A'))->unreadCount);
        self::assertSame(GetPendingNickNoticeOutcome::NoNotice, $handler->handle(new GetPendingNickNotice(11, '001B'))->outcome);
        $cache->invalidateCounts();
        self::assertSame(1, $handler->handle(new GetPendingNickNotice(10, '001A'))->unreadCount);
    }

    #[Test]
    public function returnsToIndividualCountingOutsideBatchAndMissingBatchIdsAreZero(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('countUnreadByTargetNick')->with(99)->willReturn(0);
        $memoRepository->expects(self::never())->method('countUnreadByTargetNickIds');
        $cache = new BurstMemoNoticeCache();
        $token = new stdClass();
        $cache->beginBatch([10], [], $token);
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $handler = new GetPendingNickNoticeHandler($memoRepository, $userAccountPort, $cache);

        self::assertSame(0, $handler->handle(new GetPendingNickNotice(10, '001A'))->unreadCount);
        $cache->finishBatch($token);
        self::assertSame(GetPendingNickNoticeOutcome::NoNotice, $handler->handle(new GetPendingNickNotice(99, '001C'))->outcome);
    }

    #[Test]
    public function identifiesOutsideTheActiveBatchUseTheRegularSingleCountQuery(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('countUnreadByTargetNick')->with(20)->willReturn(5);
        $memoRepository->expects(self::never())->method('countUnreadByTargetNickIds');
        $cache = new BurstMemoNoticeCache();
        $cache->beginBatch([10], [10 => 1], new stdClass());
        $userAccountPort = $this->createStub(MemoUserAccountPort::class);
        $userAccountPort->method('getLanguage')->willReturn('en');
        $handler = new GetPendingNickNoticeHandler($memoRepository, $userAccountPort, $cache);

        $result = $handler->handle(new GetPendingNickNotice(20, '001B'));

        self::assertSame(GetPendingNickNoticeOutcome::PendingMemos, $result->outcome);
        self::assertSame(5, $result->unreadCount);
    }
}
