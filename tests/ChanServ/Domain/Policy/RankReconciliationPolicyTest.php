<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Policy;

use App\ChanServ\Domain\Policy\RankReconciliationPolicy;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChange;
use App\ChanServ\Domain\ValueObject\RankChangeAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RankReconciliationPolicy::class)]
#[CoversClass(RankChange::class)]
#[CoversClass(RankChangeAction::class)]
final class RankReconciliationPolicyTest extends TestCase
{
    private RankReconciliationPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new RankReconciliationPolicy();
    }

    #[Test]
    public function ordinaryJoinGrantsMissingDesiredRankWithoutDowngrading(): void
    {
        self::assertSame(
            [['operator', 'grant']],
            $this->describe($this->policy->forJoin(ChannelRank::Administrator, [ChannelRank::Administrator], ChannelRank::Operator, false)),
        );
    }

    #[Test]
    public function secureJoinRevokesOnlyTheRankSuppliedByTheJoinEventThenGrantsDesired(): void
    {
        self::assertSame(
            [['owner', 'revoke'], ['operator', 'grant']],
            $this->describe($this->policy->forJoin(
                ChannelRank::Owner,
                [ChannelRank::Administrator, ChannelRank::Owner],
                ChannelRank::Operator,
                true,
            )),
        );
    }

    #[Test]
    public function secureJoinForUserWithoutDesiredRankRemovesOnlyTheSuppliedRank(): void
    {
        self::assertSame(
            [['operator', 'revoke']],
            $this->describe($this->policy->forJoin(ChannelRank::Operator, [ChannelRank::Voice, ChannelRank::Operator], null, true)),
        );
    }

    #[Test]
    public function joinWithoutSuppliedRankDoesNotRevokePreExistingRanks(): void
    {
        self::assertSame(
            [],
            $this->policy->forJoin(null, [ChannelRank::Owner], null, true),
        );
    }

    #[Test]
    public function fullSyncDowngradesEverySupportedRankEvenWithoutSecureFact(): void
    {
        self::assertSame(
            [['owner', 'revoke'], ['administrator', 'revoke'], ['voice', 'grant']],
            $this->describe($this->policy->forFullSync(
                [ChannelRank::Owner, ChannelRank::Administrator],
                ChannelRank::Voice,
                ChannelRank::highestFirst(),
            )),
        );
    }

    #[Test]
    public function fullSyncLeavesUnsupportedRanksAndDoesNotDuplicateDesiredRank(): void
    {
        self::assertSame(
            [],
            $this->policy->forFullSync(
                [ChannelRank::Owner, ChannelRank::Operator],
                ChannelRank::Operator,
                [ChannelRank::Operator, ChannelRank::Voice],
            ),
        );
    }

    #[Test]
    public function liveGrantIsRevokedOnlyWhenSecureAndAboveDesired(): void
    {
        self::assertSame(
            [['administrator', 'revoke']],
            $this->describe($this->policy->forLiveGrant(ChannelRank::Administrator, ChannelRank::Operator, true)),
        );
        self::assertSame([], $this->policy->forLiveGrant(ChannelRank::Administrator, ChannelRank::Operator, false));
        self::assertSame([], $this->policy->forLiveGrant(ChannelRank::Voice, ChannelRank::Operator, true));
    }

    /**
     * @param list<RankChange> $changes
     *
     * @return list<array{string, string}>
     */
    private function describe(array $changes): array
    {
        return array_map(
            static fn (RankChange $change): array => [$change->rank->value, $change->action->value],
            $changes,
        );
    }
}
