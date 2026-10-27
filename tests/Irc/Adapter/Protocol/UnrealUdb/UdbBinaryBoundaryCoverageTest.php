<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbLogicalDigest;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbNativeRowCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbWireCodec;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function array_fill;
use function fclose;
use function file_put_contents;
use function fopen;
use function fwrite;
use function hash;
use function implode;
use function pack;
use function rewind;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

use const PHP_INT_MAX;

#[CoversClass(UdbLogicalDigest::class)]
#[CoversClass(UdbNativeRowCodec::class)]
#[CoversClass(UdbChecksum::class)]
#[CoversClass(UdbSchema::class)]
#[CoversClass(UdbPathCodec::class)]
#[CoversClass(UdbWireCodec::class)]
final class UdbBinaryBoundaryCoverageTest extends TestCase
{
    #[Test]
    #[DataProvider('corruptFrames')]
    public function truncatedDigestFramesFailClosed(string $bytes, string $message): void
    {
        $stream = fopen('php://memory', 'w+');
        self::assertIsResource($stream);
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $read = new ReflectionMethod(UdbLogicalDigest::class, 'readFrame');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage($message);
            $read->invoke(null, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function corruptFrames(): iterable
    {
        yield 'partial header' => ['x', 'Corrupt UDB digest spool header.'];
        yield 'short sort key' => [pack('N2', 2, 0) . str_repeat("\0", 32) . 'x', 'Corrupt UDB digest spool row.'];
        yield 'short logical row' => [pack('N2', 0, 2) . str_repeat("\0", 32) . 'x', 'Corrupt UDB digest spool row.'];
    }

    #[Test]
    public function mapDigestUsesTheSameTypedRowsAndRejectsUntypedMaps(): void
    {
        $rows = ['nick::pass' => UdbValue::string('*literal')];
        self::assertSame(UdbLogicalDigest::fromRecords(UdbBlock::Nicks, [['nick::pass', $rows['nick::pass']]]), UdbChecksum::fromMap(UdbBlock::Nicks, $rows));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('typed values');
        UdbLogicalDigest::fromMap(UdbBlock::Nicks, ['nick::pass' => 'untyped']);
    }

    #[Test]
    public function invalidTypedRecordIsRejectedBeforeSpooling(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('typed');
        UdbLogicalDigest::fromRecords(UdbBlock::Nicks, [['nick', 'untyped']]);
    }

    #[Test]
    public function nativeIdentityAndOrderingUseBinaryLengthFraming(): void
    {
        $parts = ['ab', 'c'];
        self::assertSame(hash('sha256', "domain\0N" . pack('V', 2) . pack('V', 2) . 'ab' . pack('V', 1) . 'c', true), UdbNativeRowCodec::keyHash('domain', UdbBlock::Nicks, $parts));
        self::assertNotSame(UdbNativeRowCodec::keyHash('domain', UdbBlock::Nicks, $parts), UdbNativeRowCodec::keyHash('domain', UdbBlock::Nicks, ['a', 'bc']));
        self::assertSame("\x07\x02\x07\x03\0\x07\x04\0", UdbNativeRowCodec::sortKey($parts));
        self::assertSame(str_repeat("\xff", 8), UdbNativeRowCodec::decimalToLittleEndian('18446744073709551615'));
        self::assertSame(str_repeat("\0", 8), UdbNativeRowCodec::decimalToLittleEndian('0'));
    }

    #[Test]
    public function unknownSchemaAndMalformedStoredPathsCannotBecomeTypedRows(): void
    {
        self::assertFalse(UdbSchema::validateTyped(UdbBlock::Nicks, ['nick', 'unknown'], UdbValue::string('value')));
        self::assertNull(UdbSchema::typedValueFor(UdbBlock::Nicks, ['nick', 'unknown'], 'value'));
        self::assertNull(UdbSchema::typedValueForStoredRecord('?', 'nick::pass', 'value'));
        self::assertNull(UdbSchema::typedValueForStoredRecord('N', 'nick::bad%ZZ', 'value'));
        self::assertNull(UdbSchema::typedValueFor(UdbBlock::Ips, ['192.0.2.1', 'clones'], '5'));
        self::assertNull(UdbSchema::typedValueFor(UdbBlock::Ips, ['192.0.2.1', 'clones'], '*18446744073709551616'));
    }

    #[Test]
    public function conversionWidthAndEncodingBudgetRejectUnrepresentableOutput(): void
    {
        $encode = new ReflectionMethod(UdbNativeRowCodec::class, 'encodeUnsignedWidth');
        self::assertSame("\xff", $encode->invoke(null, '255', 1));
        try {
            $encode->invoke(null, '256', 1);
            self::fail('A narrowed binary width must reject overflow.');
        } catch (InvalidArgumentException $error) {
            self::assertSame('UDB numeric value exceeds uint64.', $error->getMessage());
        }
        $path = new ReflectionMethod(UdbPathCodec::class, 'encodeWithinLimits');
        self::assertSame('%3A', $path->invoke(null, ':', 1, 3));
        self::assertNull($path->invoke(null, ':', 1, 2));
        self::assertNull($path->invoke(null, 'ab', 2, 1));
        $this->expectException(InvalidArgumentException::class);
        UdbNativeRowCodec::components(UdbBlock::Nicks, 'bad%ZZ');
    }

    #[Test]
    public function duplicateCanonicalRowsRejectTheCandidateInsteadOfCountingTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical key collision');
        UdbLogicalDigest::fromRecords(UdbBlock::Nicks, [['Nick::pass', UdbValue::string('one')], ['nick::PASS', UdbValue::string('two')]]);
    }

    #[Test]
    public function checkedAggregateArithmeticRejectsNegativeAndOverflow(): void
    {
        $add = new ReflectionMethod(UdbLogicalDigest::class, 'checkedAdd');
        self::assertSame(PHP_INT_MAX, $add->invoke(null, PHP_INT_MAX - 1, 1));
        foreach ([[PHP_INT_MAX, 1], [0, -1]] as [$left, $right]) {
            try {
                $add->invoke(null, $left, $right);
                self::fail('Invalid aggregate arithmetic must fail closed.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('integer range', $error->getMessage());
            }
        }
        $pack = new ReflectionMethod(UdbLogicalDigest::class, 'packUint64');
        $this->expectException(InvalidArgumentException::class);
        $pack->invoke(null, -1);
    }

    #[Test]
    public function fullWireSizeAndComponentCountAreEnforced(): void
    {
        self::assertFalse(UdbPathCodec::isCanonicalPath(implode('::', array_fill(0, 9, 'a'))));
        self::assertTrue(UdbPathCodec::fitsWireLine('001', '002', 'PUT', 'N::nick', 'fixture'));
        self::assertFalse(UdbPathCodec::fitsWireLine('001', '002', 'PUT', str_repeat('a', 16384), 'fixture'));
        $this->expectException(InvalidArgumentException::class);
        new ReflectionMethod(UdbWireCodec::class, 'line')->invoke(null, str_repeat('a', 16384));
    }

    #[Test]
    public function corruptPrivateRadixSpoolAtDepthLimitFailsClosed(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ares-radix-corrupt-');
        self::assertIsString($file);
        try {
            $row = str_repeat('x', 16777217);
            file_put_contents($file, pack('N2', 1, strlen($row)) . str_repeat("\0", 32) . 'a' . $row);
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('radix depth');
            new ReflectionMethod(UdbLogicalDigest::class, 'leaf')->invoke(null, UdbBlock::Nicks, 0, $file, 256, str_repeat("\0", 32), sys_get_temp_dir());
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function malformedPutHeaderCannotYieldAFrame(): void
    {
        $codec = UdbWireCodec::class;
        foreach ([':001 DB 002 PUT 1 I tx N::nick !svalue', ':001 DB 002 PUT 0 I tx I::192.0.2.1 !n'] as $line) {
            self::assertNull($codec::parse(IRCMessage::fromRawLine($line)));
        }
    }
}
