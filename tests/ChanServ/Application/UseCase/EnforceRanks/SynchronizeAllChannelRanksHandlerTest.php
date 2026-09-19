<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\EnforceRanks;

use App\ChanServ\Application\Model\ChannelRankPolicy;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanks;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceRanks\SynchronizeAllChannelRanksHandler;
use App\ChanServ\Domain\ValueObject\ChannelLevelSet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SynchronizeAllChannelRanksHandler::class)]
final class SynchronizeAllChannelRanksHandlerTest extends TestCase
{
    #[Test]
    public function synchronizesOnlyUnblockedChannelsAndCountsChanges(): void
    {
        $repository = $this->createStub(ChannelRankPolicyRepository::class);
        $repository->method('all')->willReturn([$this->policy('#one'), $this->policy('#blocked', true)]);
        $handler = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $handler->expects(self::once())->method('handleKnownPolicy')->with(
            self::callback(static fn (EnforceChannelRanks $command): bool => '#one' === $command->channelName),
            self::callback(static fn (ChannelRankPolicy $policy): bool => '#one' === $policy->name),
        )->willReturn(new RankEnforcementResult(RankEnforcementOutcome::Applied, 3));

        self::assertSame(3, new SynchronizeAllChannelRanksHandler($repository, $handler)->handle());
    }

    private function policy(string $name, bool $blocked = false): ChannelRankPolicy
    {
        return new ChannelRankPolicy(1, $name, 10, false, $blocked, ChannelLevelSet::defaults(), []);
    }
}
