<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbChecksum::class)]
final class UdbChecksumTest extends TestCase
{
    #[Test]
    public function emptyRecordSetUsesTheStandardSha256Digest(): void
    {
        self::assertSame(hash('sha256', ''), UdbChecksum::EMPTY);
        self::assertSame(UdbChecksum::EMPTY, UdbChecksum::fromRecords([]));
        self::assertSame(UdbChecksum::EMPTY, UdbChecksum::fromLines([]));
    }

    #[Test]
    public function logicalRecordsAreSortedBytewiseAndNewlineTerminated(): void
    {
        $expected = hash('sha256', "N::a::pass v1\nN::b::pass v2\n");

        self::assertSame($expected, UdbChecksum::fromRecords([['N::b::pass', 'v2'], ['N::a::pass', 'v1']]));
        self::assertSame($expected, UdbChecksum::fromLines(['N::a::pass v1', 'N::b::pass v2']));
        self::assertNotSame(UdbChecksum::fromRecords([['a', '1']]), UdbChecksum::fromRecords([['a', '2']]));
    }

    #[Test]
    public function parseAcceptsOnlyCanonicalLowercaseSha256(): void
    {
        $digest = str_repeat('a', 64);
        self::assertSame($digest, UdbChecksum::parse($digest));
        self::assertNull(UdbChecksum::parse(strtoupper($digest)));
        self::assertNull(UdbChecksum::parse(str_repeat('a', 63)));
        self::assertNull(UdbChecksum::parse(str_repeat('g', 64)));
        self::assertNull(UdbChecksum::parse(''));
    }
}
