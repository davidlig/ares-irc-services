<?php

declare(strict_types=1);

namespace App\Tests\Application\Shared\Time;

use App\Application\Shared\Time\RelativeExpiryParser;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RelativeExpiryParser::class)]
final class RelativeExpiryParserTest extends TestCase
{
    #[Test]
    #[DataProvider('relativeExpiryProvider')]
    public function parseReturnsExpectedDateForRelativeExpiry(string $value, string $expected): void
    {
        $now = new DateTimeImmutable('2026-05-10 12:00:00');

        self::assertSame($expected, RelativeExpiryParser::parse($value, $now)?->format('Y-m-d H:i:s'));
    }

    public static function relativeExpiryProvider(): iterable
    {
        yield 'days' => ['7d', '2026-05-17 12:00:00'];
        yield 'hours' => ['12h', '2026-05-11 00:00:00'];
        yield 'minutes' => ['30m', '2026-05-10 12:30:00'];
        yield 'normalizes whitespace and case' => [' 2D ', '2026-05-12 12:00:00'];
    }

    #[Test]
    public function parseReturnsNullForPermanentExpiry(): void
    {
        self::assertNull(RelativeExpiryParser::parse(' 0 '));
    }

    #[Test]
    #[DataProvider('invalidExpiryProvider')]
    public function parseReturnsNullForInvalidExpiry(string $value): void
    {
        self::assertNull(RelativeExpiryParser::parse($value, new DateTimeImmutable('2026-05-10 12:00:00')));
    }

    public static function invalidExpiryProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'missing unit' => ['10'];
        yield 'unsupported unit' => ['10w'];
        yield 'negative value' => ['-1d'];
        yield 'space between value and unit' => ['1 d'];
    }

    #[Test]
    public function isPermanentNormalizesValue(): void
    {
        self::assertTrue(RelativeExpiryParser::isPermanent(' 0 '));
        self::assertFalse(RelativeExpiryParser::isPermanent('1d'));
    }
}
