<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Protocol;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function crc32;
use function dechex;

use const PHP_INT_SIZE;

#[CoversClass(UdbChecksum::class)]
final class UdbChecksumTest extends TestCase
{
    #[Test]
    public function emptyRecordSetHashesToZero(): void
    {
        self::assertSame('00000000', UdbChecksum::fromRecords([]));
        self::assertSame('00000000', UdbChecksum::fromLines([]));
    }

    #[Test]
    public function digestMatchesStandardCrc32Implementation(): void
    {
        // CRC-32 of the canonical test vector must match the reflected IEEE
        // implementation the UDB C module uses (init/xorout 0xFFFFFFFF).
        $expected = 'CBF43926';
        if (8 === PHP_INT_SIZE) {
            $expected = strtoupper(dechex(crc32('123456789')));
        }

        self::assertSame($expected, strtoupper(hash('crc32b', '123456789')));
    }

    #[Test]
    public function linesAreSortedAndNewlineTerminatedBeforeHashing(): void
    {
        $expected = strtoupper(hash('crc32b', "N::a::pass v1\nN::b::pass v2\n"));

        self::assertSame(
            $expected,
            UdbChecksum::fromRecords([['N::b::pass', 'v2'], ['N::a::pass', 'v1']]),
        );
        self::assertSame(
            $expected,
            UdbChecksum::fromLines(['N::a::pass v1', 'N::b::pass v2']),
        );
    }

    #[Test]
    public function differentContentProducesDifferentDigests(): void
    {
        $first = UdbChecksum::fromRecords([['a', '1']]);
        $second = UdbChecksum::fromRecords([['a', '2']]);

        self::assertNotSame($first, $second);
        self::assertMatchesRegularExpression('/^[0-9A-F]{8}$/', $first);
    }

    #[Test]
    public function parseNormalizesToEightUppercaseHexDigits(): void
    {
        self::assertSame('0000000A', UdbChecksum::parse('a'));
        self::assertSame('0000000A', UdbChecksum::parse('0a'));
        self::assertSame('ABCDEF12', UdbChecksum::parse('abcdef12'));
        self::assertNull(UdbChecksum::parse(''));
    }

    #[Test]
    public function parseRejectsInvalidChecksums(): void
    {
        self::assertNull(UdbChecksum::parse('123456789'));
        self::assertNull(UdbChecksum::parse('GHIJKLMN'));
        self::assertNull(UdbChecksum::parse('12 34'));
    }
}
