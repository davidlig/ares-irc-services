<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbStructuralNodeCount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbStructuralNodeCount::class)]
final class UdbStructuralNodeCountTest extends TestCase
{
    #[Test]
    public function countsSharedParentsAndEmptyBlocksLikeUdb(): void
    {
        self::assertSame(0, UdbStructuralNodeCount::fromRecords(UdbBlock::Channels, []));
        self::assertSame(9, UdbStructuralNodeCount::fromRecords(UdbBlock::Channels, [
            '#opers::founder' => 'davidlig',
            '#opers::options' => '*6',
            '#opers::modes' => '+ntims',
            '#ares::topic' => 'support',
            '#ares::modes' => '+ntM',
            '#ares::founder' => 'davidlig',
            '#ares::options' => '*6',
        ]));
        self::assertSame(14, UdbStructuralNodeCount::fromRecords(UdbBlock::Nicks, [
            'davidlig::vhost' => 'example.net',
            'davidlig::oper' => 'netadmin',
            'Ares::pass' => 'hash',
            'davidlig::pass' => 'hash',
            'OperServ::forbid' => 'reserved',
            'MemoServ::forbid' => 'reserved',
            'ChanServ::forbid' => 'reserved',
            'NickServ::forbid' => 'reserved',
        ]));
        self::assertSame(7, UdbStructuralNodeCount::fromRecords(UdbBlock::Settings, [
            'a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5', 'f' => '6', 'g' => '7',
        ]));
    }

    #[Test]
    public function normalizesOrdinaryComponentsButPreservesKlinePatternIdentity(): void
    {
        self::assertSame(3, UdbStructuralNodeCount::fromRecords(UdbBlock::Channels, [
            '#Ares::topic' => 'first',
            '#ares::modes' => '+nt',
        ]));
        self::assertSame(6, UdbStructuralNodeCount::fromRecords(UdbBlock::Lines, [
            'F::Mask::reason' => 'one',
            'f::Mask::expires' => '123',
            'F::mask::reason' => 'two',
        ]));
    }

    #[Test]
    public function treatsPercentEncodedComponentBytesAsOpaqueToPathSplitting(): void
    {
        self::assertSame(3, UdbStructuralNodeCount::fromRecords(UdbBlock::Nicks, [
            'nick%3Aname::pass' => 'hash',
            'nick%3Aname::vhost' => 'example.net',
        ]));
    }
}
