<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\ChannelRankModeMapper;
use App\ChanServ\Adapter\Out\Network\IrcChannelRankActions;
use App\ChanServ\Application\Model\MemberRankChange;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChange;
use App\ChanServ\Domain\ValueObject\RankChangeAction;
use App\Shared\Application\Port\ChannelServiceActionsPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelRankActions::class)]
final class IrcChannelRankActionsTest extends TestCase
{
    #[Test]
    public function itPreservesSignOrderAndBatchesAtMostSixChangesPerCommand(): void
    {
        $calls = [];
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::exactly(3))->method('setChannelModes')->willReturnCallback(
            static function (string $channel, string $modes, array $params) use (&$calls): void {
                $calls[] = [$channel, $modes, $params];
            },
        );
        $changes = [
            $this->change('U1', ChannelRank::Operator, RankChangeAction::Grant),
            $this->change('U2', ChannelRank::Voice, RankChangeAction::Revoke),
            $this->change('U3', ChannelRank::HalfOperator, RankChangeAction::Revoke),
            $this->change('U4', ChannelRank::Owner, RankChangeAction::Grant),
            $this->change('U5', ChannelRank::Administrator, RankChangeAction::Grant),
            $this->change('U6', ChannelRank::Voice, RankChangeAction::Grant),
            $this->change('U7', ChannelRank::Owner, RankChangeAction::Revoke),
            $this->change('U8', ChannelRank::Administrator, RankChangeAction::Revoke),
            $this->change('U9', ChannelRank::Operator, RankChangeAction::Revoke),
            $this->change('U10', ChannelRank::HalfOperator, RankChangeAction::Revoke),
            $this->change('U11', ChannelRank::Voice, RankChangeAction::Revoke),
            $this->change('U12', ChannelRank::Operator, RankChangeAction::Grant),
            $this->change('U13', ChannelRank::Voice, RankChangeAction::Grant),
        ];

        new IrcChannelRankActions($network, new ChannelRankModeMapper())->apply('#Channel', $changes);

        self::assertSame([
            ['#Channel', '+o-vh+qav', ['U1', 'U2', 'U3', 'U4', 'U5', 'U6']],
            ['#Channel', '-qaohv+o', ['U7', 'U8', 'U9', 'U10', 'U11', 'U12']],
            ['#Channel', '+v', ['U13']],
        ], $calls);
    }

    #[Test]
    public function itDoesNotSendAnEmptyBatch(): void
    {
        $network = $this->createMock(ChannelServiceActionsPort::class);
        $network->expects(self::never())->method('setChannelModes');

        new IrcChannelRankActions($network, new ChannelRankModeMapper())->apply('#channel', []);
    }

    private function change(string $uid, ChannelRank $rank, RankChangeAction $action): MemberRankChange
    {
        return new MemberRankChange($uid, new RankChange($rank, $action));
    }
}
