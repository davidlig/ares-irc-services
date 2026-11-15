<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Model;

use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BurstRankEventScope::class)]
final class BurstRankEventScopeTest extends TestCase
{
    #[Test]
    public function scopeIsCaseInsensitiveAndClearedAtEnd(): void
    {
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        self::assertFalse($scope->active());
        self::assertNull($scope->channel('#none'));
        $scope->begin();
        $policy = new ChannelRankPolicy(1, '#Test', 10, false, false, ChannelLevelSet::defaults(), []);
        $network = new ChannelRankNetworkState('#Test', [], []);
        $scope->setChannel($policy, $network);
        self::assertSame([$policy, $network], $scope->channel('#TEST'));
        self::assertNull($scope->channel('#other'));
        $scope->markActivity('#Test');
        self::assertTrue($scope->needsActivity('#TEST'));
        $scope->end();
        self::assertFalse($scope->active());
        self::assertNull($scope->channel('#test'));
        self::assertFalse($scope->needsActivity('#test'));
    }

    #[Test]
    public function scopeFoldsChannelNamesWithTheRfc1459Mapping(): void
    {
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        $scope->begin();
        $policy = new ChannelRankPolicy(1, '#[Ares]', 10, false, false, ChannelLevelSet::defaults(), []);
        $scope->setChannel($policy, null);

        self::assertSame([$policy, null], $scope->channel('#{ARES}'));
        self::assertNull($scope->channel('#(ares)'));
        $scope->markActivity('#[Ares]');
        self::assertTrue($scope->needsActivity('#{ares}'));
        self::assertFalse($scope->needsActivity('#(ares)'));
    }

    #[Test]
    public function scopeMatchesNetworkChannelsWithTheNegotiatedAsciiMapping(): void
    {
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ(CaseMapping::Ascii));
        $scope->begin();
        $policy = new ChannelRankPolicy(1, '#a[', 10, false, false, ChannelLevelSet::defaults(), []);
        $scope->setChannel($policy, null);

        self::assertNull($scope->channel('#a{'));
        self::assertSame([$policy, null], $scope->channel('#A['));
        $scope->markActivity('#a{');
        self::assertFalse($scope->needsActivity('#a['));
        $scope->markActivity('#A[');
        self::assertTrue($scope->needsActivity('#a['));
    }
}
