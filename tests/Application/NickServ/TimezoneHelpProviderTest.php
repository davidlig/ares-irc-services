<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\TimezoneHelpProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function in_array;

#[CoversClass(TimezoneHelpProvider::class)]
final class TimezoneHelpProviderTest extends TestCase
{
    #[Test]
    public function getRegionsReturnsNonEmptyArray(): void
    {
        $provider = new TimezoneHelpProvider();
        $regions = $provider->getRegions();

        self::assertIsArray($regions);
        self::assertNotEmpty($regions);
        foreach ($regions as $r) {
            self::assertIsString($r);
        }
    }

    #[Test]
    public function getTimezonesForRegionReturnsSortedList(): void
    {
        $provider = new TimezoneHelpProvider();
        $regions = $provider->getRegions();
        self::assertNotEmpty($regions);

        $tzs = $provider->getTimezonesForRegion($regions[0]);
        self::assertIsArray($tzs);
        $sorted = $tzs;
        sort($sorted);
        self::assertSame($sorted, $tzs);
    }

    #[Test]
    public function resolveRegionReturnsCanonicalNameCaseInsensitive(): void
    {
        $provider = new TimezoneHelpProvider();
        $regions = $provider->getRegions();
        if ([] === $regions) {
            self::markTestSkipped('No timezone regions');
        }

        $first = $regions[0];
        self::assertSame($first, $provider->resolveRegion(strtolower($first)));
    }

    #[Test]
    public function getRegionForTimezoneReturnsRegionForSlashIdentifier(): void
    {
        $provider = new TimezoneHelpProvider();
        $result = $provider->getRegionForTimezone('Europe/Madrid');

        self::assertSame('Europe', $result);
    }

    #[Test]
    public function getRegionForTimezoneReturnsNullForNoSlash(): void
    {
        $provider = new TimezoneHelpProvider();

        self::assertNull($provider->getRegionForTimezone('UTC'));
    }

    #[Test]
    public function getRegionForTimezoneReturnsNullForEmptyOrEtc(): void
    {
        $provider = new TimezoneHelpProvider();
        self::assertNull($provider->getRegionForTimezone(''));
        self::assertNull($provider->getRegionForTimezone('Etc/GMT'));
    }

    #[Test]
    public function getTimezonesForRegionReturnsEmptyForUnknownRegion(): void
    {
        $provider = new TimezoneHelpProvider();
        self::assertSame([], $provider->getTimezonesForRegion('UnknownRegion'));
        self::assertSame([], $provider->getTimezonesForRegion(''));
    }

    #[Test]
    public function resolveRegionReturnsCanonicalName(): void
    {
        $provider = new TimezoneHelpProvider();
        self::assertSame('Europe', $provider->resolveRegion('Europe'));
        self::assertSame('America', $provider->resolveRegion('AMERICA'));
        self::assertSame('Asia', $provider->resolveRegion('asia'));
        self::assertNull($provider->resolveRegion('InvalidRegion'));
        self::assertNull($provider->resolveRegion(''));
    }

    #[Test]
    public function getRegionForTimezoneReturnsNullForUnknownPrefix(): void
    {
        $provider = new TimezoneHelpProvider();
        self::assertNull($provider->getRegionForTimezone('Unknown/City'));
    }

    #[Test]
    public function resolveRegionHandlesWhitespace(): void
    {
        $provider = new TimezoneHelpProvider();
        self::assertSame('Europe', $provider->resolveRegion('  Europe  '));
        self::assertNull($provider->resolveRegion('   '));
    }

    #[Test]
    public function getRegionForTimezoneHandlesWhitespace(): void
    {
        $provider = new TimezoneHelpProvider();
        self::assertSame('Europe', $provider->getRegionForTimezone('  Europe/Madrid  '));
        self::assertNull($provider->getRegionForTimezone('   '));
    }

    #[Test]
    public function getTimezonesForRegionHandlesWhitespace(): void
    {
        $provider = new TimezoneHelpProvider();
        $regions = $provider->getRegions();
        if ([] === $regions) {
            self::markTestSkipped('No timezone regions');
        }
        $tzs = $provider->getTimezonesForRegion('  ' . $regions[0] . '  ');
        self::assertNotEmpty($tzs);
    }

    #[Test]
    public function ensureLoadedInitializesStaticCache(): void
    {
        $provider = new TimezoneHelpProvider();
        $regions = $provider->getRegions();
        self::assertNotEmpty($regions);
        $secondProvider = new TimezoneHelpProvider();
        $regions2 = $secondProvider->getRegions();
        self::assertSame($regions, $regions2);
    }

    #[Test]
    public function getRegionsReturnsAllExpectedRegions(): void
    {
        $provider = new TimezoneHelpProvider();
        $regions = $provider->getRegions();
        $expectedRegions = ['Africa', 'America', 'Antarctica', 'Arctic', 'Asia', 'Atlantic', 'Australia', 'Europe', 'Indian', 'Pacific'];
        foreach ($expectedRegions as $expected) {
            self::assertContains($expected, $regions, "Region {$expected} should be in the list");
        }
    }

    #[Test]
    public function getTimezonesForRegionFiltersIdentifiersWithoutSlash(): void
    {
        $provider = new TimezoneHelpProvider();
        $europeTimezones = $provider->getTimezonesForRegion('Europe');
        foreach ($europeTimezones as $tz) {
            self::assertStringContainsString('/', $tz, 'Each timezone should contain a slash');
            self::assertStringStartsWith('Europe/', $tz, "Each timezone should start with 'Europe/'");
        }
    }

    #[Test]
    public function getRegionsReturnsRegionsInExpectedOrder(): void
    {
        $provider = new TimezoneHelpProvider();
        $regions = $provider->getRegions();

        $expectedOrder = ['Africa', 'America', 'Antarctica', 'Arctic', 'Asia', 'Atlantic', 'Australia', 'Europe', 'Indian', 'Pacific'];
        $expectedInRegions = array_filter($expectedOrder, static fn ($r) => in_array($r, $regions, true));
        $actualInExpected = array_filter($regions, static fn ($r) => in_array($r, $expectedOrder, true));

        self::assertSame($expectedInRegions, $actualInExpected, 'Regions should be in PHP manual order');
    }

    #[Test]
    public function getTimezonesForRegionNormalizesRegionCase(): void
    {
        $provider = new TimezoneHelpProvider();

        $canonical = $provider->getTimezonesForRegion('Europe');
        $lowercase = $provider->getTimezonesForRegion('europe');
        $uppercase = $provider->getTimezonesForRegion('EUROPE');
        $mixedCase = $provider->getTimezonesForRegion('EuRoPe');

        self::assertSame($canonical, $lowercase, 'Lowercase region should return same result');
        self::assertSame($canonical, $uppercase, 'Uppercase region should return same result');
        self::assertSame($canonical, $mixedCase, 'Mixed case region should return same result');
    }

    #[Test]
    public function identifersWithoutSlashAreExcludedFromRegionLists(): void
    {
        $provider = new TimezoneHelpProvider();
        $allTimezones = [];

        foreach ($provider->getRegions() as $region) {
            foreach ($provider->getTimezonesForRegion($region) as $tz) {
                $allTimezones[] = $tz;
            }
        }

        self::assertNotContains('UTC', $allTimezones, 'UTC has no slash and should be excluded');
        self::assertNotContains('GMT', $allTimezones, 'GMT has no slash and should be excluded');

        foreach ($allTimezones as $tz) {
            self::assertMatchesRegularExpression('/^[A-Za-z]+\/.+/', $tz, 'All identifiers should contain a slash after region prefix');
        }
    }

    #[Test]
    public function ensureLoadedInitializesByRegionWhenNull(): void
    {
        $reflection = new ReflectionClass(TimezoneHelpProvider::class);
        $property = $reflection->getProperty('byRegion');
        $property->setAccessible(true);
        $property->setValue(null, null);

        $provider = new TimezoneHelpProvider();
        $regions = $provider->getRegions();

        self::assertNotEmpty($regions);

        $byRegion = $property->getValue();
        self::assertIsArray($byRegion);
        self::assertArrayHasKey('Europe', $byRegion);
    }
}
