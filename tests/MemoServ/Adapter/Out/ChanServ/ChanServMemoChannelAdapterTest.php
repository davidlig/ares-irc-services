<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\ChanServ;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\MemoServ\Adapter\Out\ChanServ\ChanServMemoChannelAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServMemoChannelAdapter::class)]
final class ChanServMemoChannelAdapterTest extends TestCase
{
    private function createAccessHelper(
        ?ChannelAccessRepositoryInterface $access = null,
        ?ChannelLevelRepositoryInterface $levels = null,
    ): ChanServAccessHelper {
        return new ChanServAccessHelper(
            $access ?? $this->createStub(ChannelAccessRepositoryInterface::class),
            $levels ?? $this->createStub(ChannelLevelRepositoryInterface::class),
        );
    }

    #[Test]
    public function findChannelByNameReturnsViewWhenFound(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getName')->willReturn('#php');
        $channel->method('getFounderNickId')->willReturn(1);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByChannelName')->with('#php')->willReturn($channel);

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper());

        $view = $adapter->findChannelByName('#PHP');

        self::assertNotNull($view);
        self::assertSame(10, $view->id);
        self::assertSame('#php', $view->name);
    }

    #[Test]
    public function findChannelByNameReturnsNullWhenNotFound(): void
    {
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByChannelName')->with('#unknown')->willReturn(null);

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper());

        self::assertNull($adapter->findChannelByName('#unknown'));
    }

    #[Test]
    public function requireReadAccessDelegatesWhenChannelExists(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(false);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByIds')->with([10])->willReturn([$channel]);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(10, 5)->willReturn(new ChannelAccess(10, 5, 200));

        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::once())->method('findByChannelAndKey')->with(10, ChannelLevel::KEY_MEMOREAD)->willReturn(new ChannelLevel(10, ChannelLevel::KEY_MEMOREAD, 100));

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper($accessRepo, $levelRepo));
        $adapter->requireReadAccess(10, 5, '#php', 'READ');
    }

    #[Test]
    public function requireReadAccessThrowsWhenInsufficientAccess(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(false);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByIds')->with([10])->willReturn([$channel]);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(10, 5)->willReturn(null);

        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::once())->method('findByChannelAndKey')->with(10, ChannelLevel::KEY_MEMOREAD)->willReturn(new ChannelLevel(10, ChannelLevel::KEY_MEMOREAD, 100));

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper($accessRepo, $levelRepo));

        $this->expectException(InsufficientAccessException::class);
        $adapter->requireReadAccess(10, 5, '#php', 'READ');
    }

    #[Test]
    public function requireReadAccessDoesNothingWhenChannelNotFound(): void
    {
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByIds')->with([999])->willReturn([]);

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper());
        $adapter->requireReadAccess(999, 5, '#php', 'READ');
    }

    #[Test]
    public function requireManageAccessDelegatesWhenChannelExists(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(false);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByIds')->with([10])->willReturn([$channel]);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(10, 5)->willReturn(new ChannelAccess(10, 5, 200));

        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::once())->method('findByChannelAndKey')->with(10, ChannelLevel::KEY_MEMOCHANGE)->willReturn(new ChannelLevel(10, ChannelLevel::KEY_MEMOCHANGE, 100));

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper($accessRepo, $levelRepo));
        $adapter->requireManageAccess(10, 5, '#php', 'DEL');
    }

    #[Test]
    public function requireManageAccessDoesNothingWhenChannelNotFound(): void
    {
        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByIds')->with([999])->willReturn([]);

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper());
        $adapter->requireManageAccess(999, 5, '#php', 'DEL');
    }

    #[Test]
    public function isChannelFounderReturnsTrueWhenFounder(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getFounderNickId')->willReturn(1);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByIds')->with([10])->willReturn([$channel]);

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper());

        self::assertTrue($adapter->isChannelFounder(10, 1));
    }

    #[Test]
    public function isChannelFounderReturnsFalseWhenNotFounderOrNotFound(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('getFounderNickId')->willReturn(1);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('findByIds')
            ->willReturnOnConsecutiveCalls([$channel], []);

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper());

        self::assertFalse($adapter->isChannelFounder(10, 999));
        self::assertFalse($adapter->isChannelFounder(20, 1));
    }

    #[Test]
    public function canReadChannelMemosReturnsTrueWhenHasAccess(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(false);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::once())->method('findByIds')->with([10])->willReturn([$channel]);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(10, 5)->willReturn(new ChannelAccess(10, 5, 200));

        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::once())->method('findByChannelAndKey')->with(10, ChannelLevel::KEY_MEMOREAD)->willReturn(new ChannelLevel(10, ChannelLevel::KEY_MEMOREAD, 100));

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper($accessRepo, $levelRepo));

        self::assertTrue($adapter->canReadChannelMemos(10, 5));
    }

    #[Test]
    public function canReadChannelMemosReturnsFalseWhenInsufficientAccessOrNotFound(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(10);
        $channel->method('isFounder')->willReturn(false);

        $repo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repo->expects(self::exactly(2))->method('findByIds')
            ->willReturnOnConsecutiveCalls([$channel], []);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(10, 5)->willReturn(null);

        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::once())->method('findByChannelAndKey')->with(10, ChannelLevel::KEY_MEMOREAD)->willReturn(new ChannelLevel(10, ChannelLevel::KEY_MEMOREAD, 100));

        $adapter = new ChanServMemoChannelAdapter($repo, $this->createAccessHelper($accessRepo, $levelRepo));

        self::assertFalse($adapter->canReadChannelMemos(10, 5));
        self::assertFalse($adapter->canReadChannelMemos(20, 5));
    }
}
