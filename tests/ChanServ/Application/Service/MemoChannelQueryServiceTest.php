<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Service;

use App\ChanServ\Application\Port\In\MemoChannel;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\Service\MemoChannelQueryService;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoChannelQueryService::class)]
#[CoversClass(MemoChannel::class)]
final class MemoChannelQueryServiceTest extends TestCase
{
    #[Test]
    public function findsAvailableChannelByNormalizedName(): void
    {
        $channel = $this->channel(10, '#PHP', false, 1);

        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::once())->method('findByChannelName')->with('#php')->willReturn($channel);

        $query = new MemoChannelQueryService($repository, $this->accessHelper());

        $result = $query->findByName('#PHP');

        self::assertNotNull($result);
        self::assertSame(10, $result->id);
        self::assertSame('#PHP', $result->name);
    }

    #[Test]
    public function doesNotExposeBlockedChannels(): void
    {
        $channel = $this->channel(10, '#blocked', true, 1);

        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::once())->method('findByChannelName')->with('#blocked')->willReturn($channel);
        $repository->expects(self::exactly(3))->method('findByIds')->with([10])->willReturn([$channel]);

        $query = new MemoChannelQueryService($repository, $this->accessHelper());

        self::assertNull($query->findByName('#blocked'));
        self::assertFalse($query->canRead(10, 1));
        self::assertFalse($query->canManage(10, 1));
        self::assertFalse($query->isFounder(10, 1));
    }

    #[Test]
    public function returnsFalseWhenChannelDisappearsDuringAuthorization(): void
    {
        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::exactly(3))->method('findByIds')->with([99])->willReturn([]);

        $query = new MemoChannelQueryService($repository, $this->accessHelper());

        self::assertFalse($query->canRead(99, 5));
        self::assertFalse($query->canManage(99, 5));
        self::assertFalse($query->isFounder(99, 5));
    }

    #[Test]
    public function evaluatesMemoReadManageAndFounderRulesInsideChanServ(): void
    {
        $channel = $this->channel(10, '#active', false, 1);

        $repository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $repository->expects(self::exactly(4))->method('findByIds')->with([10])->willReturn([$channel]);

        $accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepository->expects(self::exactly(3))->method('findByChannelAndNick')
            ->willReturnMap([
                [10, 5, new ChannelAccess(10, 5, 250)],
                [10, 6, new ChannelAccess(10, 6, 350)],
                [10, 7, null],
            ]);

        $levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepository->expects(self::exactly(3))->method('findByChannelAndKey')
            ->willReturnMap([
                [10, ChannelLevel::KEY_MEMOREAD, new ChannelLevel(10, ChannelLevel::KEY_MEMOREAD, 200)],
                [10, ChannelLevel::KEY_MEMOCHANGE, new ChannelLevel(10, ChannelLevel::KEY_MEMOCHANGE, 300)],
            ]);

        $query = new MemoChannelQueryService(
            $repository,
            new ChanServAccessHelper($accessRepository, $levelRepository),
        );

        self::assertTrue($query->canRead(10, 5));
        self::assertTrue($query->canManage(10, 6));
        self::assertFalse($query->canRead(10, 7));
        self::assertTrue($query->isFounder(10, 1));
    }

    private function accessHelper(): ChanServAccessHelper
    {
        return new ChanServAccessHelper(
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ChannelLevelRepositoryInterface::class),
        );
    }

    private function channel(int $id, string $name, bool $blocked, int $founderNickId): RegisteredChannel
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn($id);
        $channel->method('getName')->willReturn($name);
        $channel->method('isBlocked')->willReturn($blocked);
        $channel->method('isFounder')->willReturnCallback(static fn (int $nickId): bool => $nickId === $founderNickId);

        return $channel;
    }
}
