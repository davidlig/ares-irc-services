<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\TimezoneHelpProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
