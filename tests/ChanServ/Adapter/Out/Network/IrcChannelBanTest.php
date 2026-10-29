<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\IrcChannelBanActions;
use App\ChanServ\Adapter\Out\Network\IrcChannelBanNetworkQuery;
use App\Irc\Application\Port\In\ChannelBanMatchQuery;
use App\Irc\Application\Port\In\ChannelBanMatchResult;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcChannelBanActions::class)]
#[CoversClass(IrcChannelBanNetworkQuery::class)]
final class IrcChannelBanTest extends TestCase
{
    public function testMapsQueriesAndPreservesOriginalMasksAndTimestamp(): void
    {
        $query = $this->createMock(ChannelBanMatchQuery::class);
        $query->expects(self::exactly(3))->method('findMatchingBans')->willReturnOnConsecutiveCalls(null, new ChannelBanMatchResult(false), new ChannelBanMatchResult(true, ['ALICE!*@*.example', '*!*@192.0.2.0/24'], 123));
        $adapter = new IrcChannelBanNetworkQuery($query);
        self::assertNull($adapter->findMatchingBans('#test', '001AAA'));
        self::assertFalse($adapter->findMatchingBans('#test', '001AAB')?->channelExists);
        $snapshot = $adapter->findMatchingBans('#test', '001AAC');
        self::assertNotNull($snapshot);
        self::assertSame(['ALICE!*@*.example', '*!*@192.0.2.0/24'], $snapshot->masks);
        $calls = [];
        $actions = $this->createMock(ChannelServiceActionsPort::class);
        $actions->expects(self::exactly(2))->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modes, array $params, ?int $timestamp) use (&$calls): void { $calls[] = [$channel, $modes, $params, $timestamp]; });
        new IrcChannelBanActions($actions)->removeBans('#test', [], 123);
        new IrcChannelBanActions($actions)->removeBans('#test', $snapshot->masks, $snapshot->channelTimestamp);
        self::assertSame([
            ['#test', '-b', ['ALICE!*@*.example'], 123],
            ['#test', '-b', ['*!*@192.0.2.0/24'], 123],
        ], $calls);
    }
}
