<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbValue::class)]
final class UdbValueTest extends TestCase
{
    #[Test]
    public function valuesPreserveTheThreeNativeKindsAndLogicalProjection(): void
    {
        $none = UdbValue::none();
        $string = UdbValue::string('');
        $number = UdbValue::number(UdbUnsignedDecimal::parse('18446744073709551615') ?? throw new LogicException('The test uint64 fixture must be valid.'));

        self::assertSame(UdbValueKind::None, $none->kind);
        self::assertSame('', $none->value);
        self::assertSame('', $none->logicalValue());
        self::assertSame(UdbValueKind::String, $string->kind);
        self::assertSame('', $string->logicalValue());
        self::assertSame(UdbValueKind::Number, $number->kind);
        self::assertSame('18446744073709551615', $number->value);
        self::assertSame('*18446744073709551615', $number->logicalValue());
    }
}
