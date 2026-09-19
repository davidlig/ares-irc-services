<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Application\UseCase\GetPendingNickNotice;

use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNotice;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeHandler;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeOutcome;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GetPendingNickNotice::class)]
#[CoversClass(GetPendingNickNoticeHandler::class)]
#[CoversClass(GetPendingNickNoticeResult::class)]
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
}
