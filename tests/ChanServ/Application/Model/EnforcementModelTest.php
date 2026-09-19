<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Model;

use App\ChanServ\Application\Model\ChannelMember;
use App\ChanServ\Application\Model\ChannelMlockNetworkState;
use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Model\MemberRankChange;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChange;
use App\ChanServ\Domain\ValueObject\RankChangeAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRankPolicy::class)]
#[CoversClass(ChannelMlockPolicy::class)]
#[CoversClass(ChannelMember::class)]
#[CoversClass(ChannelRankNetworkState::class)]
#[CoversClass(ChannelMlockNetworkState::class)]
#[CoversClass(MemberRankChange::class)]
final class EnforcementModelTest extends TestCase
{
    #[Test]
    public function rankPolicyResolvesFounderAndStoredAccessWithoutLeakingEntities(): void
    {
        $policy = new ChannelRankPolicy(
            1,
            '#test',
            10,
            false,
            false,
            ChannelLevelSet::defaults(),
            [20 => 300],
        );

        self::assertTrue($policy->isFounder(10));
        self::assertFalse($policy->isFounder(null));
        self::assertSame(300, $policy->storedAccessFor(20));
        self::assertNull($policy->storedAccessFor(null));
        self::assertNull($policy->storedAccessFor(30));
    }

    #[Test]
    public function rankNetworkStateFindsMemberByUid(): void
    {
        $member = new ChannelMember('001A', 'Alice', true, false, false, 10, [ChannelRank::Owner]);
        $channel = new ChannelRankNetworkState('#test', [$member], [ChannelRank::Owner]);

        self::assertSame($member, $channel->member('001A'));
        self::assertNull($channel->member('missing'));
    }

    #[Test]
    public function mlockModelsContainOnlyLockAndModeState(): void
    {
        $policy = new ChannelMlockPolicy('#test', false, ChannelModeLock::active());
        $network = new ChannelMlockNetworkState('#test', [], []);

        self::assertTrue($policy->modeLock->active);
        self::assertSame('#test', $network->name);
    }

    #[Test]
    public function memberRankChangeCarriesTheCanonicalUidAndSemanticChange(): void
    {
        $rankChange = new RankChange(ChannelRank::Operator, RankChangeAction::Grant);
        $change = new MemberRankChange('001AAAA', $rankChange);

        self::assertSame('001AAAA', $change->uid);
        self::assertSame($rankChange, $change->change);
    }
}
