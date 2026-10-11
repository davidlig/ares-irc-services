<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbValueDecoder;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbValueEncoder;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbValueDecoder::class)]
#[CoversClass(UdbValueEncoder::class)]
final class UdbValueCodecTest extends TestCase
{
    #[Test]
    public function itUsesCanonicalTypedWireTagsAndRoundTripsEmptyString(): void
    {
        $cases = [
            ['!n', UdbValue::none()],
            ['!s', UdbValue::string('')],
            ['!sAA==', UdbValue::string("\0")],
            ['!sSGVsbG8=', UdbValue::string('Hello')],
            ['!u0', UdbValue::number(self::number('0'))],
            ['!u18446744073709551615', UdbValue::number(self::number('18446744073709551615'))],
        ];
        foreach ($cases as [$wire, $value]) {
            self::assertSame($wire, UdbValueEncoder::encode($value));
            self::assertTrue($value == UdbValueDecoder::decode($wire));
        }
    }

    #[Test]
    public function itRejectsUntypedLegacyAndNonCanonicalInputs(): void
    {
        foreach (['', 'hello', '*5', '!N', '!u', '!u00', '!u18446744073709551616', '!s=', '!sYQ', '!sYR=='] as $wire) {
            self::assertNull(UdbValueDecoder::decode($wire), $wire);
        }
    }

    private static function number(string $decimal): UdbUnsignedDecimal
    {
        return UdbUnsignedDecimal::parse($decimal) ?? throw new LogicException('The test uint64 fixture must be valid.');
    }
}
