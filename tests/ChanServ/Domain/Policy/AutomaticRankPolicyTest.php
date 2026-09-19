<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Domain\Policy;

use App\ChanServ\Domain\Policy\AutomaticRankPolicy;
use App\ChanServ\Domain\ValueObject\AccessLevel;
use App\ChanServ\Domain\ValueObject\ChannelLevel;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(AutomaticRankPolicy::class)]
#[CoversClass(ChannelRank::class)]
final class AutomaticRankPolicyTest extends TestCase
{
    private AutomaticRankPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AutomaticRankPolicy();
    }

    #[Test]
    public function founderReceivesHighestProtocolSupportedRank(): void
    {
        $rank = $this->policy->desiredRank(
            true,
            true,
            true,
            AccessLevel::fromEffective(500),
            ChannelLevelSet::defaults(),
            [ChannelRank::Administrator, ChannelRank::Owner, ChannelRank::Operator],
        );

        self::assertSame(ChannelRank::Owner, $rank);
    }

    #[Test]
    public function founderWithoutAnySupportedRankReceivesNone(): void
    {
        self::assertNull($this->policy->desiredRank(true, true, true, AccessLevel::fromEffective(500), ChannelLevelSet::defaults(), []));
    }

    #[Test]
    public function highestSupportedThresholdMetWins(): void
    {
        $levels = ChannelLevelSet::defaults()->withOverride(ChannelLevel::AutoAdmin, 450);

        self::assertSame(
            ChannelRank::Operator,
            $this->policy->desiredRank(true, true, false, AccessLevel::fromEffective(400), $levels, ChannelRank::highestFirst()),
        );
    }

    #[Test]
    public function unsupportedHighRanksFallThroughToSupportedLowerRank(): void
    {
        self::assertSame(
            ChannelRank::Voice,
            $this->policy->desiredRank(true, true, false, AccessLevel::fromEffective(499), ChannelLevelSet::defaults(), [ChannelRank::Voice]),
        );
    }

    #[Test]
    public function unidentifiedUnregisteredOrInsufficientUsersReceiveNoRank(): void
    {
        $supported = ChannelRank::highestFirst();
        $levels = ChannelLevelSet::defaults();

        self::assertNull($this->policy->desiredRank(false, true, true, AccessLevel::fromEffective(-1), $levels, $supported));
        self::assertNull($this->policy->desiredRank(true, false, false, AccessLevel::fromEffective(499), $levels, $supported));
        self::assertNull($this->policy->desiredRank(true, true, false, AccessLevel::fromEffective(99), $levels, $supported));
    }

    #[Test]
    public function rankOrderingIsSemanticRatherThanProtocolSpecific(): void
    {
        self::assertTrue(ChannelRank::Owner->isHigherThan(ChannelRank::Administrator));
        self::assertTrue(ChannelRank::Voice->isHigherThan(null));
        self::assertFalse(ChannelRank::Voice->isHigherThan(ChannelRank::Operator));
    }
}
