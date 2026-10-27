<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbNativeRowCodec;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;
use function str_repeat;

#[CoversClass(UdbNativeRowCodec::class)]
final class UdbNativeRowCodecTest extends TestCase
{
    #[Test]
    public function itEncodesTheSixteenByteHeaderAndLittleEndianComponents(): void
    {
        $expected = 'N' . "\x02\x01\x00" . pack('V2', 33, 1) . "\x00\x00\x00\x00"
            . pack('V', 4) . 'nick' . pack('V', 4) . 'passx';

        self::assertSame($expected, UdbNativeRowCodec::encode(UdbBlock::Nicks, ['nick', 'pass'], UdbValue::string('x')));
    }

    #[Test]
    public function itEncodesMaximumUnsignedNumberAsEightLittleEndianBytes(): void
    {
        $row = UdbNativeRowCodec::encode(UdbBlock::Settings, ['clones'], UdbValue::number(UdbUnsignedDecimal::parse('18446744073709551615') ?? throw new LogicException('The test uint64 fixture must be valid.')));

        self::assertSame('S' . "\x01\x02\x00" . pack('V2', 34, 8) . "\x00\x00\x00\x00" . pack('V', 6) . 'clones' . str_repeat("\xFF", 8), $row);
    }

    #[Test]
    public function itFoldsAsciiComponentsExceptTheFLineMaskAndLeavesHighBytesUnchanged(): void
    {
        self::assertSame(['f', 'MiXeD:Mask', 'reason'], UdbNativeRowCodec::components(UdbBlock::Lines, 'F::MiXeD%3AMask::Reason'));
        self::assertSame(['g', '*@host', 'reason'], UdbNativeRowCodec::components(UdbBlock::Lines, 'G::*@HOST::REASON'));
        self::assertSame(["\xC3\x89"], UdbNativeRowCodec::components(UdbBlock::Settings, '%C3%89'));
    }
}
