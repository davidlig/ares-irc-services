<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Domain\Entity;

use App\OperServ\Domain\Entity\GeoipCountry;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function str_repeat;

#[CoversClass(GeoipCountry::class)]
final class GeoipCountryTest extends TestCase
{
    #[Test]
    public function createNormalizesTheCountryCodeToUppercase(): void
    {
        $registeredAt = new DateTimeImmutable('2026-10-20T10:00:00+00:00');

        $country = GeoipCountry::create($registeredAt, ' es ', 'Not allowed in this network', 'Oper');

        self::assertSame('ES', $country->getCountryCode());
        self::assertSame('Not allowed in this network', $country->getReason());
        self::assertSame('Oper', $country->getRegisteredByNick());
        self::assertSame($registeredAt, $country->getRegisteredAt());
        self::assertSame('~country:ES', $country->mask());
    }

    /** @return iterable<string, array{string, string}> */
    public static function knownCodeProvider(): iterable
    {
        yield 'spain' => ['ES', 'ES'];
        yield 'lowercase spain' => ['es', 'ES'];
        yield 'padded spain' => ['  es  ', 'ES'];
        yield 'united kingdom' => ['gb', 'GB'];
        yield 'united states' => ['US', 'US'];
        yield 'morocco' => ['MA', 'MA'];
    }

    #[DataProvider('knownCodeProvider')]
    public function knownCodesAreAcceptedAndNormalized(string $input, string $expected): void
    {
        self::assertTrue(GeoipCountry::isKnownCode($input));
        self::assertSame($expected, GeoipCountry::normalizeCode($input));
        self::assertSame('~country:' . $expected, GeoipCountry::maskFor($input));
    }

    /** @return iterable<string, array{string}> */
    public static function unknownCodeProvider(): iterable
    {
        yield 'not a country' => ['XX'];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'too long' => ['ESP'];
        yield 'digits' => ['12'];
        yield 'user assigned' => ['ZZ'];
    }

    #[DataProvider('unknownCodeProvider')]
    public function unknownCodesAreRejected(string $input): void
    {
        self::assertFalse(GeoipCountry::isKnownCode($input));
    }

    #[Test]
    public function getIdReturnsIdAfterPersistence(): void
    {
        $country = GeoipCountry::create(new DateTimeImmutable('2026-10-20T10:00:00+00:00'), 'ES', 'reason', 'Oper');
        $property = new ReflectionProperty(GeoipCountry::class, 'id');
        $property->setValue($country, 123);

        self::assertSame(123, $country->getId());
    }

    #[Test]
    public function createRejectsAnUnknownCountryCode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown ISO 3166-1 alpha-2 country code "XX".');

        GeoipCountry::create(new DateTimeImmutable('2026-10-20T10:00:00+00:00'), 'XX', 'reason', 'Oper');
    }

    #[Test]
    public function createRejectsAnEmptyReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A blocked country requires a reason.');

        GeoipCountry::create(new DateTimeImmutable('2026-10-20T10:00:00+00:00'), 'ES', '', 'Oper');
    }

    #[Test]
    public function createRejectsAnOverlongReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reason cannot exceed 255 characters.');

        GeoipCountry::create(
            new DateTimeImmutable('2026-10-20T10:00:00+00:00'),
            'ES',
            str_repeat('a', 256),
            'Oper',
        );
    }

    #[Test]
    public function createRejectsAnEmptyRegisteringNickname(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A blocked country requires the registering nickname.');

        GeoipCountry::create(new DateTimeImmutable('2026-10-20T10:00:00+00:00'), 'ES', 'reason', '');
    }

    #[Test]
    public function createRejectsAnOverlongRegisteringNickname(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Nickname cannot exceed 32 characters.');

        GeoipCountry::create(
            new DateTimeImmutable('2026-10-20T10:00:00+00:00'),
            'ES',
            'reason',
            str_repeat('n', 33),
        );
    }
}
