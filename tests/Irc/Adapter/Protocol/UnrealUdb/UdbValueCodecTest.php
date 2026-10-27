<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbValueDecoder;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbValueEncoder;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;

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

    #[Test]
    public function decoderRejectsNulInOtherwiseCanonicalBase64(): void
    {
        foreach (["\0", "fixture\0tail"] as $value) {
            self::assertNull(UdbValueDecoder::decode('!s' . base64_encode($value)));
        }
    }

    #[Test]
    public function encoderRejectsNulStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        UdbValueEncoder::encode(UdbValue::string("fixture\0tail"));
    }

    #[Test]
    public function otherControlAndNonAsciiBytesRoundTripUnchanged(): void
    {
        $bytes = "fixture\x01\xff\x80\\x4142";
        $value = UdbValue::string($bytes);
        $decoded = UdbValueDecoder::decode(UdbValueEncoder::encode($value));
        self::assertNotNull($decoded);
        self::assertSame($value->kind, $decoded->kind);
        self::assertSame($bytes, $decoded->value);
    }

    private static function number(string $decimal): UdbUnsignedDecimal
    {
        return UdbUnsignedDecimal::parse($decimal) ?? throw new LogicException('The test uint64 fixture must be valid.');
    }
}
