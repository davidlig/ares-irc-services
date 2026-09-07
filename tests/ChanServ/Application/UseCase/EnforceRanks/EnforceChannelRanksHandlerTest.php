<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\EnforceRanks;

use App\ChanServ\Application\Model\ChannelMember;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Model\MemberRankChange;
use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanks;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandler;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementTrigger;
use App\ChanServ\Domain\Policy\AutomaticRankPolicy;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\Policy\RankReconciliationPolicy;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChangeAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[CoversClass(EnforceChannelRanks::class)]
#[CoversClass(EnforceChannelRanksHandler::class)]
#[CoversClass(RankEnforcementResult::class)]
final class EnforceChannelRanksHandlerTest extends TestCase
{
    #[Test]
    public function reportsUnavailableAndBlockedChannelsBeforeNetworkLookup(): void
    {
        $policies = $this->createStub(ChannelRankPolicyRepository::class);
        $policies->method('findByName')->willReturnOnConsecutiveCalls(null, $this->policy(blocked: true));
        $network = $this->createMock(ChannelRankNetworkQuery::class);
        $network->expects(self::never())->method('findChannel');
        $handler = $this->handler($policies, $network, $this->createStub(ChannelRankActions::class));

        self::assertSame(RankEnforcementOutcome::ChannelUnavailable, $handler->handle($this->command())->outcome);
        self::assertSame(RankEnforcementOutcome::ChannelBlocked, $handler->handle($this->command())->outcome);
    }

    #[Test]
    public function channelSynchronizationTouchesActivityEvenWhenChannelRankNetworkStateIsUnavailable(): void
    {
        $policy = $this->policy();
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->method('findByName')->willReturn($policy);
        $policies->expects(self::once())->method('touchLastUsed')->with(1);
        $network = $this->createStub(ChannelRankNetworkQuery::class);
        $network->method('findChannel')->willReturn(null);

        $result = $this->handler($policies, $network, $this->createStub(ChannelRankActions::class))->handle(
            new EnforceChannelRanks('#test', RankEnforcementTrigger::ChannelSynchronized),
        );

        self::assertSame(RankEnforcementOutcome::NoChanges, $result->outcome);
        self::assertTrue($result->activityTouched);
    }

    #[Test]
    public function ordinaryEnforcementReportsUnavailableChannelRankNetworkState(): void
    {
        $network = $this->createStub(ChannelRankNetworkQuery::class);
        $network->method('findChannel')->willReturn(null);

        $result = $this->handler($this->policyRepository($this->policy()), $network, $this->createStub(ChannelRankActions::class))
            ->handle($this->command());

        self::assertSame(RankEnforcementOutcome::ChannelUnavailable, $result->outcome);
    }

    #[Test]
    public function joinedIdentifiedFounderGetsHighestSupportedRankAndTouchesActivity(): void
    {
        $member = $this->member(identified: true, nickId: 10);
        $network = $this->network([$member], [ChannelRank::Administrator, ChannelRank::Operator]);
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->method('findByName')->willReturn($this->policy(founder: 10));
        $policies->expects(self::once())->method('touchLastUsed')->with(1);
        $actions = $this->createMock(ChannelRankActions::class);
        $actions->expects(self::once())->method('apply')->with(
            '#test',
            self::callback(static fn (array $changes): bool => 1 === count($changes)
                && $changes[0] instanceof MemberRankChange
                && '001A' === $changes[0]->uid
                && ChannelRank::Administrator === $changes[0]->change->rank
                && RankChangeAction::Grant === $changes[0]->change->action),
        );

        $result = $this->handler($policies, $network, $actions)->handle(
            new EnforceChannelRanks('#test', RankEnforcementTrigger::MemberJoined, '001A'),
        );

        self::assertSame(RankEnforcementOutcome::Applied, $result->outcome);
        self::assertSame(1, $result->changeCount);
        self::assertTrue($result->activityTouched);
    }

    #[Test]
    public function secureJoinRevokesRankFromUnidentifiedMemberWithoutTouchingActivity(): void
    {
        $member = $this->member(ranks: [ChannelRank::Operator]);
        $actions = $this->createMock(ChannelRankActions::class);
        $actions->expects(self::once())->method('apply')->with('#test', self::callback(static function (array $changes): bool {
            $change = $changes[0] ?? null;

            return $change instanceof MemberRankChange && RankChangeAction::Revoke === $change->change->action;
        }));

        $result = $this->handler(
            $this->policyRepository($this->policy(secure: true)),
            $this->network([$member]),
            $actions,
        )->handle(new EnforceChannelRanks(
            '#test',
            RankEnforcementTrigger::MemberJoined,
            '001A',
            joinedRank: ChannelRank::Operator,
        ));

        self::assertSame(RankEnforcementOutcome::Applied, $result->outcome);
        self::assertFalse($result->activityTouched);
    }

    #[Test]
    public function missingMemberAndMissingLiveGrantProduceSemanticNoops(): void
    {
        $network = $this->network([]);
        $handler = $this->handler($this->policyRepository($this->policy()), $network, $this->createStub(ChannelRankActions::class));

        self::assertSame(
            RankEnforcementOutcome::MemberUnavailable,
            $handler->handle(new EnforceChannelRanks('#test', RankEnforcementTrigger::MemberJoined, 'missing'))->outcome,
        );

        $memberNetwork = $this->network([$this->member(identified: true, nickId: 20)]);
        $handler = $this->handler($this->policyRepository($this->policy(access: [20 => 300])), $memberNetwork, $this->createStub(ChannelRankActions::class));
        self::assertSame(
            RankEnforcementOutcome::NoChanges,
            $handler->handle(new EnforceChannelRanks('#test', RankEnforcementTrigger::LiveRankGranted, '001A'))->outcome,
        );
    }

    #[Test]
    public function liveSecureGrantAboveDesiredIsRevoked(): void
    {
        $member = $this->member(identified: true, nickId: 20, ranks: [ChannelRank::Owner]);
        $actions = $this->createMock(ChannelRankActions::class);
        $actions->expects(self::once())->method('apply')->with('#test', self::callback(static function (array $changes): bool {
            $change = $changes[0] ?? null;

            return $change instanceof MemberRankChange
                && ChannelRank::Owner === $change->change->rank
                && RankChangeAction::Revoke === $change->change->action;
        }));

        $result = $this->handler(
            $this->policyRepository($this->policy(secure: true, access: [20 => 300])),
            $this->network([$member]),
            $actions,
        )->handle(new EnforceChannelRanks('#test', RankEnforcementTrigger::LiveRankGranted, '001A', ChannelRank::Owner));

        self::assertSame(RankEnforcementOutcome::Applied, $result->outcome);
    }

    #[Test]
    public function fullSyncBatchesMemberChangesAndSkipsServiceMember(): void
    {
        $member = $this->member(identified: true, nickId: 20, ranks: [ChannelRank::Administrator, ChannelRank::Operator]);
        $service = new ChannelMember('001S', 'ChanServ', true, false, true, null, [ChannelRank::Owner]);
        $actions = $this->createMock(ChannelRankActions::class);
        $actions->expects(self::once())->method('apply')->with('#test', self::callback(static function (array $changes): bool {
            $change = $changes[0] ?? null;

            return 1 === count($changes)
                && $change instanceof MemberRankChange
                && '001A' === $change->uid
                && ChannelRank::Administrator === $change->change->rank;
        }));

        $result = $this->handler(
            $this->policyRepository($this->policy(access: [20 => 300])),
            $this->network([$member, $service]),
            $actions,
        )->handle($this->command());

        self::assertSame(1, $result->changeCount);
        self::assertSame(RankEnforcementOutcome::Applied, $result->outcome);
    }

    #[Test]
    public function fullSyncWithoutChangesReturnsNoChangesAndChannelSyncStillTouches(): void
    {
        $member = $this->member(identified: true, nickId: 20, ranks: [ChannelRank::Operator]);
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->method('findByName')->willReturn($this->policy(access: [20 => 300]));
        $policies->expects(self::once())->method('touchLastUsed')->with(1);
        $actions = $this->createMock(ChannelRankActions::class);
        $actions->expects(self::never())->method('apply');
        $handler = $this->handler($policies, $this->network([$member]), $actions);

        self::assertSame(RankEnforcementOutcome::NoChanges, $handler->handle($this->command())->outcome);
        $syncResult = $handler->handle(new EnforceChannelRanks('#test', RankEnforcementTrigger::ChannelSynchronized));
        self::assertSame(RankEnforcementOutcome::Applied, $syncResult->outcome);
        self::assertTrue($syncResult->activityTouched);
    }

    #[Test]
    public function departureTouchesOnlyIdentifiedMembersWithAnAutomaticRank(): void
    {
        $member = $this->member(identified: true, nickId: 20);
        $policies = $this->createMock(ChannelRankPolicyRepository::class);
        $policies->method('findByName')->willReturn($this->policy(access: [20 => 300]));
        $policies->expects(self::once())->method('touchLastUsed')->with(1);
        $handler = $this->handler($policies, $this->network([$member]), $this->createStub(ChannelRankActions::class));

        $result = $handler->handle(new EnforceChannelRanks('#test', RankEnforcementTrigger::MemberLeft, '001A'));
        self::assertTrue($result->activityTouched);

        $missing = $handler->handle(new EnforceChannelRanks('#test', RankEnforcementTrigger::MemberLeft, 'missing'));
        self::assertSame(RankEnforcementOutcome::MemberUnavailable, $missing->outcome);

        $anonymous = $this->handler(
            $this->policyRepository($this->policy()),
            $this->network([$this->member()]),
            $this->createStub(ChannelRankActions::class),
        )->handle(new EnforceChannelRanks('#test', RankEnforcementTrigger::MemberLeft, '001A'));
        self::assertSame(RankEnforcementOutcome::NoChanges, $anonymous->outcome);
    }

    private function command(): EnforceChannelRanks
    {
        return new EnforceChannelRanks('#test', RankEnforcementTrigger::NetworkSynchronized);
    }

    /** @param array<int, int> $access */
    private function policy(bool $secure = false, bool $blocked = false, int $founder = 10, array $access = []): ChannelRankPolicy
    {
        return new ChannelRankPolicy(
            1,
            '#test',
            $founder,
            $secure,
            $blocked,
            ChannelLevelSet::defaults(),
            $access,
        );
    }

    /** @param list<ChannelRank> $ranks */
    private function member(bool $identified = false, ?int $nickId = null, array $ranks = []): ChannelMember
    {
        return new ChannelMember('001A', 'Alice', $identified, false, false, $nickId, $ranks);
    }

    /**
     * @param list<ChannelMember> $members
     * @param list<ChannelRank>   $supported
     */
    private function network(array $members, array $supported = [ChannelRank::Owner, ChannelRank::Administrator, ChannelRank::Operator, ChannelRank::HalfOperator, ChannelRank::Voice]): ChannelRankNetworkQuery
    {
        $network = $this->createStub(ChannelRankNetworkQuery::class);
        $network->method('findChannel')->willReturn(new ChannelRankNetworkState('#test', $members, $supported));

        return $network;
    }

    private function policyRepository(ChannelRankPolicy $policy): ChannelRankPolicyRepository
    {
        $repository = $this->createStub(ChannelRankPolicyRepository::class);
        $repository->method('findByName')->willReturn($policy);

        return $repository;
    }

    private function handler(
        ChannelRankPolicyRepository $policies,
        ChannelRankNetworkQuery $network,
        ChannelRankActions $actions,
    ): EnforceChannelRanksHandler {
        return new EnforceChannelRanksHandler(
            $policies,
            $network,
            $actions,
            new ChannelAccessPolicy(),
            new AutomaticRankPolicy(),
            new RankReconciliationPolicy(),
        );
    }
}
