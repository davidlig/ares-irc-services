<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbPathCodec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

use const PHP_INT_MAX;

#[CoversClass(UdbPathCodec::class)]
final class UdbPathCodecTest extends TestCase
{
    #[Test]
    public function plainPrintableComponentsAreNotEscaped(): void
    {
        self::assertSame('#channel', UdbPathCodec::encodeComponent('#channel'));
        self::assertSame('user@host.tld', UdbPathCodec::encodeComponent('user@host.tld'));
        self::assertSame('#channel', UdbPathCodec::decodeComponent('#channel'));
    }

    #[Test]
    public function specialBytesArePercentEncoded(): void
    {
        self::assertSame('%3A', UdbPathCodec::encodeComponent(':'));
        self::assertSame('%25', UdbPathCodec::encodeComponent('%'));
        self::assertSame('%20', UdbPathCodec::encodeComponent(' '));
        self::assertSame('a%3Ab', UdbPathCodec::encodeComponent('a:b'));
    }

    #[Test]
    public function controlAndHighBytesAreEncodedAsTwoHexDigits(): void
    {
        self::assertSame('%01', UdbPathCodec::encodeComponent("\x01"));
        self::assertSame('%7F', UdbPathCodec::encodeComponent("\x7f"));
        self::assertSame('%C3%A9', UdbPathCodec::encodeComponent('é'));
    }

    #[Test]
    public function decodeRejectsMalformedEscapes(): void
    {
        self::assertNull(UdbPathCodec::decodeComponent('%'));
        self::assertNull(UdbPathCodec::decodeComponent('%4'));
        self::assertNull(UdbPathCodec::decodeComponent('%G1'));
        self::assertNull(UdbPathCodec::decodeComponent('%00'));
    }

    #[Test]
    public function decodeRejectsNonCanonicalEscapes(): void
    {
        // 'A' would be encoded literally; %41 is not canonical.
        self::assertNull(UdbPathCodec::decodeComponent('%41'));
        // Literal ':' is passed through by the decoder; canonicality of the
        // full path is enforced by isCanonicalPath()/encodeComponent().
        self::assertSame(':', UdbPathCodec::decodeComponent(':'));
        self::assertSame(':', UdbPathCodec::decodeComponent('%3a'));
        self::assertSame(':', UdbPathCodec::decodeComponent('%3A'));
    }

    #[Test]
    public function decodePassesLiteralBytesThrough(): void
    {
        self::assertSame('user@host', UdbPathCodec::decodeComponent('user@host'));
    }

    #[Test]
    public function encodeRejectsOversizedComponents(): void
    {
        self::assertNull(UdbPathCodec::encodeComponent(str_repeat('a', UdbPathCodec::COMPONENT_RAW_MAX + 1)));
    }

    #[Test]
    public function encodeRejectsComponentsWhoseEscapedFormExceedsTheEncodedBudget(): void
    {
        // 1536 escapes produce exactly COMPONENT_ENCODED_MAX bytes.
        self::assertNotNull(UdbPathCodec::encodeComponent(str_repeat(':', 1536)));
        self::assertNull(UdbPathCodec::encodeComponent(str_repeat(':', 1537)));
    }

    #[Test]
    public function decodeRejectsOversizedLiteralComponents(): void
    {
        self::assertNull(UdbPathCodec::decodeComponent(str_repeat('a', UdbPathCodec::COMPONENT_RAW_MAX + 1)));
    }

    #[Test]
    public function decodeRejectsOversizedEscapedComponents(): void
    {
        self::assertNull(UdbPathCodec::decodeComponent(str_repeat('%3A', UdbPathCodec::COMPONENT_RAW_MAX + 1)));
    }

    #[Test]
    public function isCanonicalPathRejectsOversizedEncodedComponents(): void
    {
        self::assertFalse(UdbPathCodec::isCanonicalPath(str_repeat('a', UdbPathCodec::COMPONENT_ENCODED_MAX + 1)));
    }

    #[Test]
    public function encodePathJoinsComponentsWithSeparator(): void
    {
        self::assertSame('nick::pass', UdbPathCodec::encodePath(['nick', 'pass']));
        self::assertSame('a%3Ab::c', UdbPathCodec::encodePath(['a:b', 'c']));
        self::assertNull(UdbPathCodec::encodePath([]));
        self::assertNull(UdbPathCodec::encodePath(['']));
        self::assertNull(UdbPathCodec::encodePath([str_repeat(':', 1600)]));
    }

    #[Test]
    public function isCanonicalPathValidatesEveryComponent(): void
    {
        self::assertTrue(UdbPathCodec::isCanonicalPath('nick::pass'));
        self::assertTrue(UdbPathCodec::isCanonicalPath('G::user@host::reason'));
        self::assertFalse(UdbPathCodec::isCanonicalPath('nick::%41'));
        self::assertFalse(UdbPathCodec::isCanonicalPath('G::user%40host::reason'));
        self::assertFalse(UdbPathCodec::isCanonicalPath('nick::a%'));
        self::assertFalse(UdbPathCodec::isCanonicalPath(''));
        self::assertFalse(UdbPathCodec::isCanonicalPath('nick::::pass'));
        // Lowercase hex decodes fine but re-encodes to uppercase: not canonical.
        self::assertFalse(UdbPathCodec::isCanonicalPath('nick::%3a'));
    }

    #[Test]
    public function isCanonicalPathRejectsOversizedPathsAndComponents(): void
    {
        self::assertFalse(UdbPathCodec::isCanonicalPath(str_repeat('a', UdbPathCodec::PATH_MAX + 1)));
        self::assertFalse(UdbPathCodec::isCanonicalPath(str_repeat('a', UdbPathCodec::COMPONENT_ENCODED_MAX + 1) . '::b'));
    }

    #[Test]
    public function fitsLimitsAcceptsTypicalRecords(): void
    {
        self::assertTrue(UdbPathCodec::fitsLimits('N::nick::pass', 'sha256:' . str_repeat('a', 64)));
        self::assertTrue(UdbPathCodec::fitsLimits('C::#chan::topic', 'hello world'));
        self::assertTrue(UdbPathCodec::fitsLimits('S::key', null));
    }

    #[Test]
    public function fitsLimitsRejectsCrlfOversizedAndEmptyPaths(): void
    {
        self::assertFalse(UdbPathCodec::fitsLimits('', 'x'));
        self::assertFalse(UdbPathCodec::fitsLimits(str_repeat('a', UdbPathCodec::PATH_MAX + 1), null));
        self::assertFalse(UdbPathCodec::fitsLimits('a', "bad\nvalue"));
        self::assertFalse(UdbPathCodec::fitsLimits('a', "bad\rvalue"));
        self::assertFalse(UdbPathCodec::fitsLimits('a', str_repeat('b', UdbPathCodec::VALUE_MAX + 1)));
    }

    #[Test]
    public function fitsLimitsHonorsBudgetAtExtremes(): void
    {
        // Largest representable record: two full-size components (8192 path
        // bytes) plus a 4096-byte value still fits the serialized budget.
        $path = str_repeat('a', 4600) . '::' . str_repeat('b', 3590);
        self::assertSame(8192, strlen($path));
        self::assertTrue(UdbPathCodec::fitsLimits($path, str_repeat('v', UdbPathCodec::VALUE_MAX)));
    }

    #[Test]
    public function fitsLimitsEnforcesSerializedLineBudget(): void
    {
        // A 4600-byte component is legal per-component but the combined
        // serialized line exceeds the record line budget.
        $path = str_repeat('a', 4600) . '::' . str_repeat('b', 3592);
        self::assertSame(8194, strlen($path));
        self::assertFalse(UdbPathCodec::fitsLimits($path, str_repeat('v', 100)));
    }

    #[Test]
    public function isValidTxidAcceptsAlphanumericDashUnderscoreUpTo31(): void
    {
        self::assertTrue(UdbPathCodec::isValidTxid('00000001'));
        self::assertTrue(UdbPathCodec::isValidTxid('tx-1_2'));
        self::assertTrue(UdbPathCodec::isValidTxid(str_repeat('a', UdbPathCodec::TXID_MAX)));
        self::assertFalse(UdbPathCodec::isValidTxid(''));
        self::assertFalse(UdbPathCodec::isValidTxid(str_repeat('a', UdbPathCodec::TXID_MAX + 1)));
        self::assertFalse(UdbPathCodec::isValidTxid('bad.txid'));
        self::assertFalse(UdbPathCodec::isValidTxid('bad txid'));
    }

    #[Test]
    public function parseUnsignedIsStrict(): void
    {
        self::assertSame('0', (string) UdbPathCodec::parseUnsigned('0'));
        self::assertSame('7', (string) UdbPathCodec::parseUnsigned('007'));
        self::assertSame('4294967295', (string) UdbPathCodec::parseUnsigned('4294967295'));
        self::assertSame('4294967296', (string) UdbPathCodec::parseUnsigned('4294967296'));
        self::assertSame((string) PHP_INT_MAX, (string) UdbPathCodec::parseUnsigned((string) PHP_INT_MAX));
        self::assertSame('9223372036854775808', (string) UdbPathCodec::parseUnsigned('9223372036854775808'));
        self::assertSame('18446744073709551615', (string) UdbPathCodec::parseUnsigned('18446744073709551615'));
        self::assertNull(UdbPathCodec::parseUnsigned(''));
        self::assertNull(UdbPathCodec::parseUnsigned('abc'));
        self::assertNull(UdbPathCodec::parseUnsigned('12a'));
        self::assertNull(UdbPathCodec::parseUnsigned('-1'));
        self::assertNull(UdbPathCodec::parseUnsigned('+1'));
        self::assertNull(UdbPathCodec::parseUnsigned(' 1'));
        self::assertNull(UdbPathCodec::parseUnsigned('18446744073709551616'));
        self::assertSame(1024, UdbPathCodec::parseUnsignedInt('1024', 1024));
        self::assertNull(UdbPathCodec::parseUnsignedInt('invalid', 1024));
        self::assertNull(UdbPathCodec::parseUnsignedInt('1025', 1024));
        self::assertNull(UdbPathCodec::parseUnsignedInt('9223372036854775808', PHP_INT_MAX));
    }

    #[Test]
    public function parseTimeTRespectsTheSignedTimeTBounds(): void
    {
        self::assertSame(0, UdbPathCodec::parseTimeT('0'));
        self::assertSame(1700000000, UdbPathCodec::parseTimeT('1700000000'));
        self::assertSame(PHP_INT_MAX, UdbPathCodec::parseTimeT((string) PHP_INT_MAX));
        self::assertSame(1, UdbPathCodec::parseTimeT('0001'));
        self::assertNull(UdbPathCodec::parseTimeT('9223372036854775808'));
        self::assertNull(UdbPathCodec::parseTimeT('18446744073709551615'));
        self::assertNull(UdbPathCodec::parseTimeT(''));
        self::assertNull(UdbPathCodec::parseTimeT('-1'));
        self::assertNull(UdbPathCodec::parseTimeT('+1'));
        self::assertNull(UdbPathCodec::parseTimeT(' 1'));
        self::assertNull(UdbPathCodec::parseTimeT('1 '));
        self::assertNull(UdbPathCodec::parseTimeT('1a'));
    }

    #[Test]
    public function normalizeChecksumPadsAndUppercases(): void
    {
        self::assertSame('0000000F', UdbPathCodec::normalizeChecksum('f'));
        self::assertNull(UdbPathCodec::normalizeChecksum('123456789'));
        self::assertNull(UdbPathCodec::normalizeChecksum('nothex'));
    }
}
