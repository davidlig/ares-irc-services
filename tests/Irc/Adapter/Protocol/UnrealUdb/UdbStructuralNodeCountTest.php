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
    public function infCountIsLogicalRowsRatherThanImplicitPrefixNodes(): void
    {
        self::assertSame(0, UdbStructuralNodeCount::fromRecords(UdbBlock::Channels, []));
        self::assertSame(7, UdbStructuralNodeCount::fromRecords(UdbBlock::Channels, [
            '#opers::founder' => 'davidlig', '#opers::options' => '*6', '#opers::modes' => '+ntims',
            '#ares::topic' => 'support', '#ares::modes' => '+ntM', '#ares::founder' => 'davidlig', '#ares::options' => '*6',
        ]));
        self::assertSame(8, UdbStructuralNodeCount::fromRecords(UdbBlock::Nicks, [
            'davidlig::vhost' => 'example.net', 'davidlig::oper' => 'netadmin', 'Ares::pass' => 'hash',
            'davidlig::pass' => 'hash', 'OperServ::forbid' => 'reserved', 'MemoServ::forbid' => 'reserved',
            'ChanServ::forbid' => 'reserved', 'NickServ::forbid' => 'reserved',
        ]));
    }

    #[Test]
    public function separateLeavesUnderOneSharedParentCountAsSeparateRows(): void
    {
        // Persistence enforces unique canonical identities; this facade counts supplied rows only.
        self::assertSame(2, UdbStructuralNodeCount::fromRecords(UdbBlock::Channels, [
            '#Ares::topic' => 'one', '#ares::modes' => '+nt',
        ]));
    }
}
