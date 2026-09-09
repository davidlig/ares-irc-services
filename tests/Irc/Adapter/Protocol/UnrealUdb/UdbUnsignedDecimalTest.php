<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;

#[CoversClass(UdbUnsignedDecimal::class)]
final class UdbUnsignedDecimalTest extends TestCase
{
    #[Test]
    public function canonicalizesComparesAndConvertsWithoutIntegerOverflow(): void
    {
        $seven = UdbUnsignedDecimal::parse('0007');
        $eight = UdbUnsignedDecimal::parse('8');
        $ten = UdbUnsignedDecimal::parse('10');
        $abovePhp = UdbUnsignedDecimal::parse('9223372036854775808');

        self::assertNotNull($seven);
        self::assertNotNull($eight);
        self::assertNotNull($ten);
        self::assertNotNull($abovePhp);
        self::assertSame('7', (string) $seven);
        self::assertTrue($seven->equals(UdbUnsignedDecimal::fromInt(7)));
        self::assertSame(-1, $seven->compare($eight));
        self::assertSame(1, $eight->compare($seven));
        self::assertSame(-1, $seven->compare($ten));
        self::assertSame(0, $seven->compare(UdbUnsignedDecimal::fromInt(7)));
        self::assertSame(7, $seven->toInt());
        self::assertNull($abovePhp->toInt());
        self::assertFalse($seven->isZero());
        self::assertTrue(UdbUnsignedDecimal::fromInt(0)->isZero());
    }

    #[Test]
    public function parseRejectsMalformedAndOverflowingDecimals(): void
    {
        self::assertNull(UdbUnsignedDecimal::parse(''));
        self::assertNull(UdbUnsignedDecimal::parse('-1'));
        self::assertNull(UdbUnsignedDecimal::parse('18446744073709551616'));
    }

    #[Test]
    public function incrementDoesNotOverflowTheUnsignedLongContract(): void
    {
        $value = UdbUnsignedDecimal::parse('999');
        $maximum = UdbUnsignedDecimal::parse(UdbUnsignedDecimal::MAX);

        self::assertNotNull($value);
        self::assertNotNull($maximum);
        self::assertSame('1000', (string) $value->increment());
        self::assertNull($maximum->increment());
        self::assertSame('1', (string) $maximum->incrementNonZero());
    }

    #[Test]
    public function fromIntRejectsNegativeValues(): void
    {
        $this->expectException(ValueError::class);

        UdbUnsignedDecimal::fromInt(-1);
    }
}
