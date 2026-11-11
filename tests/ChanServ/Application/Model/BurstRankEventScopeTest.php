<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Model;

use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BurstRankEventScope::class)]
final class BurstRankEventScopeTest extends TestCase
{
    #[Test]
    public function scopeIsCaseInsensitiveAndClearedAtEnd(): void
    {
        $scope = new BurstRankEventScope();
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
}
