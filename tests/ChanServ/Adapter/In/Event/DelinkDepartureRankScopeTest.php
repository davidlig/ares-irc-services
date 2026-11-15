<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\DelinkDepartureRankScope;
use App\ChanServ\Application\Model\ChannelMember;
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
        $network->expects(self::never())->method('findMember');
        $scope = new DelinkDepartureRankScope($policies, $network);

        $scope->begin();

        self::assertSame([$blocked, null], $scope->departure('#blocked', 'U1'));
        self::assertSame([$blocked, null], $scope->departure('#blocked', 'U2'));
    }

    #[Test]
    public function anUnregisteredChannelIsLoadedOnceAndNeverQueriesTheNetwork(): void
    {
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->expects(self::once())->method('findByName')->with('#free')->willReturn(null);
        $network = $this->createMock(ChannelRankNetworkQuery::class);
        $network->expects(self::never())->method('findMember');
        $scope = new DelinkDepartureRankScope($policies, $network);

        $scope->begin();

        self::assertNull($scope->departure('#free', 'U1'));
        self::assertNull($scope->departure('#free', 'U2'));
    }

    #[Test]
    public function aFewDeparturesFromAHugeChannelResolveOnlyTheDepartingMembers(): void
    {
        $policy = new ChannelRankPolicy(1, '#huge', 10, false, false, ChannelLevelSet::defaults(), []);
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->expects(self::once())->method('findByName')->with('#huge')->willReturn($policy);
        $resolved = [];
        $network = $this->createMock(ChannelRankNetworkQuery::class);
        $network->expects(self::never())->method('findChannel');
        $network->expects(self::never())->method('findChannelWithAccounts');
        $network->expects(self::exactly(3))->method('findMember')->willReturnCallback(
            static function (string $channel, string $uid) use (&$resolved): ChannelRankNetworkState {
                $resolved[] = [$channel, $uid];

                return new ChannelRankNetworkState($channel, [new ChannelMember($uid, 'N' . $uid, true, false, false, 7, [])], []);
            },
        );
        $scope = new DelinkDepartureRankScope($policies, $network);

        $scope->begin();
        foreach (['U1', 'U2', 'U3'] as $uid) {
            $snapshot = $scope->departure('#huge', $uid);
            self::assertNotNull($snapshot);
            self::assertSame($policy, $snapshot[0]);
            self::assertSame($uid, $snapshot[1]?->member($uid)?->uid);
        }

        self::assertSame([['#huge', 'U1'], ['#huge', 'U2'], ['#huge', 'U3']], $resolved);
    }

    #[Test]
    public function aNewDelinkReloadsChannelsTheLastOneCached(): void
    {
        $policy = new ChannelRankPolicy(1, '#test', 10, false, false, ChannelLevelSet::defaults(), []);
        $state = new ChannelRankNetworkState('#test', [], []);
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->expects(self::exactly(2))->method('findByName')->with('#test')->willReturn($policy);
        $network = $this->createMock(ChannelRankNetworkQuery::class);
        $network->expects(self::exactly(2))->method('findMember')->with('#test', 'U1')->willReturn($state);
        $scope = new DelinkDepartureRankScope($policies, $network);

        $scope->begin();
        self::assertSame([$policy, $state], $scope->departure('#test', 'U1'));
        $scope->end();
        $scope->begin();

        self::assertSame([$policy, $state], $scope->departure('#test', 'U1'));
    }
}
