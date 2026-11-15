<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\DelinkDepartureRankScope;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DelinkDepartureRankScope::class)]
final class DelinkDepartureRankScopeTest extends TestCase
{
    #[Test]
    public function itIsInactiveUntilADelinkStartsAndAfterItFinishes(): void
    {
        $scope = new DelinkDepartureRankScope(
            $this->createStub(ChannelRankPolicyRepository::class),
            $this->createStub(ChannelRankNetworkQuery::class),
        );

        self::assertFalse($scope->active());
        $scope->begin();
        self::assertTrue($scope->active());
        $scope->end();
        self::assertFalse($scope->active());
    }

    #[Test]
    public function aBlockedChannelIsCachedWithoutLoadingItsNetworkState(): void
    {
        $blocked = new ChannelRankPolicy(1, '#blocked', 10, false, true, ChannelLevelSet::defaults(), []);
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->expects(self::once())->method('findByName')->with('#blocked')->willReturn($blocked);
        $network = $this->createMock(ChannelRankNetworkQuery::class);
        $network->expects(self::never())->method('findChannel');
        $scope = new DelinkDepartureRankScope($policies, $network);

        $scope->begin();

        self::assertSame([$blocked, null], $scope->channel('#blocked'));
        self::assertSame([$blocked, null], $scope->channel('#blocked'));
    }

    #[Test]
    public function aNewDelinkReloadsChannelsTheLastOneCached(): void
    {
        $policy = new ChannelRankPolicy(1, '#test', 10, false, false, ChannelLevelSet::defaults(), []);
        $state = new ChannelRankNetworkState('#test', [], []);
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->expects(self::exactly(2))->method('findByName')->with('#test')->willReturn($policy);
        $network = $this->createMock(ChannelRankNetworkQuery::class);
        $network->expects(self::exactly(2))->method('findChannel')->with('#test')->willReturn($state);
        $scope = new DelinkDepartureRankScope($policies, $network);

        $scope->begin();
        self::assertSame([$policy, $state], $scope->channel('#test'));
        $scope->end();
        $scope->begin();

        self::assertSame([$policy, $state], $scope->channel('#test'));
    }
}
