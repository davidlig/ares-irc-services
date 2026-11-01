<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealStandalone;

use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneAutomaticModeInventory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

#[CoversClass(UnrealStandaloneAutomaticModeInventory::class)]
final class UnrealStandaloneAutomaticModeInventoryTest extends TestCase
{
    /**
     * @param list<string> $lines
     * @param array<string, string>|null $expected
     */
    #[Test]
    #[DataProvider('inventoryCases')]
    public function tracksKnownNetworkCapabilities(array $lines, ?array $expected): void
    {
        $inventory = new UnrealStandaloneAutomaticModeInventory('002');
        foreach ($lines as $line) {
            $inventory->observe(IRCMessage::fromRawLine($line));
        }
        self::assertSame($expected, $inventory->getAutomaticModes());
    }

    /** @return iterable<string, array{list<string>, array<string, string>|null}> */
    public static function inventoryCases(): iterable
    {
        $bot = ['bot' => 'B'];
        $direct = ['PROTOCTL SID=001 USERMODES=B', 'SERVER ircd.test 1 :IRCd'];
        yield 'unknown before discovery' => [[], null];
        yield 'SID alone is unknown' => [['PROTOCTL SID=001'], null];
        yield 'modes before SID' => [['PROTOCTL USERMODES=B', 'PROTOCTL SID=001'], $bot];
        yield 'SID before modes' => [['PROTOCTL SID=001', 'PROTOCTL USERMODES=B'], $bot];
        yield 'known no ordinary modes' => [['PROTOCTL SID=001 USERMODES=orHz'], []];
        yield 'all safe intentions only' => [['PROTOCTL SID=001 USERMODES=ioswxzHdItwBSTRGWpqZD'], ['invisible' => 'i', 'wallops' => 'w', 'bot' => 'B', 'no-ctcp' => 'T', 'censor' => 'G', 'hide-channels' => 'p', 'registered-only' => 'R', 'tls-only' => 'Z', 'private-deaf' => 'D', 'channel-deaf' => 'd']];
        yield 'remote unknown blocks' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf'], null];
        yield 'intersection' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':003 SINFO 1 6100 Bd * * :IRCd'], $bot];
        yield 'case sensitive intersection' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':003 SINFO 1 6100 b * * :IRCd'], []];
        yield 'remote runtime update' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':003 SINFO 1 6100 B * * :IRCd', ':003 SINFO 1 6100 d * * :IRCd'], []];
        yield 'remote unknown update' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':003 SINFO 1 6100 B * * :IRCd', ':003 SINFO 1 6100 * * * :IRCd'], null];
        yield 'malformed remote update invalidates' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':003 SINFO 1 6100 B * * :IRCd', ':003 SINFO 1'], null];
        yield 'direct prefix update' => [[...$direct, ':001 PROTOCTL USERMODES=d'], ['channel-deaf' => 'd']];
        yield 'direct name update' => [[...$direct, ':IRCD.TEST PROTOCTL USERMODES=d'], ['channel-deaf' => 'd']];
        yield 'remote cannot forge direct modes' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':003 SINFO 1 6100 B * * :IRCd', ':003 PROTOCTL USERMODES=d SID=003'], $bot];
        yield 'direct SINFO cannot forge remote capability' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':001 SINFO 1 6100 B * * :IRCd'], null];
        yield 'direct SINFO does not override PROTOCTL' => [[...$direct, ':001 SINFO 1 6100 d * * :IRCd'], $bot];
        yield 'unintroduced SINFO ignored' => [[...$direct, ':003 SINFO 1 6100 d * * :IRCd'], $bot];
        yield 'invalid source introduction ignored' => [[...$direct, ':999 SID bad.test 2 003 :Bad'], $bot];
        yield 'local service introduction ignored' => [[...$direct, ':001 SID services.test 2 002 :Ares'], $bot];
        yield 'legacy SERVER unknown until SINFO' => [[...$direct, ':001 SERVER leaf.test 2 :Leaf'], null];
        yield 'legacy SERVER modes' => [[...$direct, ':001 SERVER leaf.test 2 :Leaf', ':LEAF.TEST SINFO 1 6100 B * * :IRCd'], $bot];
        yield 'split removes descendants' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':003 SID child.test 3 004 :Child', ':001 SQUIT leaf.test :Split'], $bot];
        yield 'split preserves unrelated server capabilities' => [[...$direct, 'PROTOCTL USERMODES=Bd', ':001 SID leaf.test 2 003 :Leaf', ':001 SID sibling.test 2 005 :Sibling', ':005 SINFO 1 6100 d * * :IRCd', ':001 SQUIT 003 :Split'], ['channel-deaf' => 'd']];
        yield 'split by SID' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':001 SQUIT 003 :Split'], $bot];
        yield 'unknown split ignored' => [[...$direct, ':001 SQUIT 999 :Unknown'], $bot];
        yield 'untrusted split ignored' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':999 SQUIT 003 :Spoof'], null];
        yield 'direct split clears' => [[...$direct, ':001 SQUIT 001 :Lost'], null];
        yield 'error clears' => [[...$direct, 'ERROR :Lost'], null];
        yield 'malformed command ignored' => [[...$direct, ':001 SID', ':001 SERVER', ':001 SQUIT', ':001 PRIVMSG x :hello'], $bot];
        yield 'invalid SID ignored' => [['PROTOCTL SID=bad USERMODES=B'], null];
        yield 'empty inventory unknown' => [[...$direct, 'PROTOCTL USERMODES='], null];
        yield 'unknown inventory' => [[...$direct, 'PROTOCTL USERMODES=*'], null];
        yield 'malformed letters unknown' => [[...$direct, 'PROTOCTL USERMODES=+B'], null];
        yield 'oversized inventory unknown' => [[...$direct, 'PROTOCTL USERMODES=' . str_repeat('B', 65)], null];
        yield 'prefix cannot establish direct peer' => [[':999 PROTOCTL SID=001 USERMODES=B'], null];
        yield 'missing direct SERVER name ignored' => [[...$direct, 'SERVER'], $bot];
        yield 'duplicate introduction retains inventory' => [[...$direct, ':001 SID leaf.test 2 003 :Leaf', ':003 SINFO 1 6100 B * * :IRCd', ':001 SID leaf.test 2 003 :Leaf'], $bot];
    }

    #[Test]
    public function resetDropsAllPreviouslyKnownCapabilities(): void
    {
        $inventory = new UnrealStandaloneAutomaticModeInventory('002');
        $inventory->observe(IRCMessage::fromRawLine('PROTOCTL SID=001 USERMODES=B'));
        self::assertSame(['bot' => 'B'], $inventory->getAutomaticModes());
        $inventory->reset();
        self::assertNull($inventory->getAutomaticModes());
        $inventory->observe(IRCMessage::fromRawLine('PROTOCTL SID=005 USERMODES=d'));
        self::assertSame(['channel-deaf' => 'd'], $inventory->getAutomaticModes());
    }
}
