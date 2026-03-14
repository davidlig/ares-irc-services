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
}
