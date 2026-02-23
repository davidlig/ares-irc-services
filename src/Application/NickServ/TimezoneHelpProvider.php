<?php

declare(strict_types=1);

namespace App\Application\NickServ;

use DateTimeZone;

/**
 * Provides timezone regions and identifiers for HELP SET TIMEZONE.
 *
 * Uses DateTimeZone::listIdentifiers() to get the full list; regions follow
 * the same order as the PHP timezone docs (Africa, America, ..., Pacific).
 */
final class TimezoneHelpProvider
{
    /**
     * Valid regions in PHP manual order (Africa through Pacific).
     *
     * @var string[]
     */
    private const array REGION_ORDER = [
        'Africa',
        'America',
        'Antarctica',
        'Arctic',
        'Asia',
        'Atlantic',
        'Australia',
        'Europe',
        'Indian',
        'Pacific',
    ];

    /** @var array<string, string[]>|null */
    private static ?array $byRegion = null;

    /**
     * Returns region names in PHP manual order, from the loaded timezone data.
     * Only regions that exist in the current PHP timezone database are included.
     *
     * @return string[]
     */
    public function getRegions(): array
    {
        $this->ensureLoaded();

        $regions = [];
        foreach (self::REGION_ORDER as $displayName) {
            if (isset(self::$byRegion[$displayName])) {
                $regions[] = $displayName;
            }
        }

        return $regions;
    }

    /**
     * Returns timezone identifiers for a region (e.g. "Europe" → ["Europe/Amsterdam", ...]).
     *
     * @return string[] Sorted list of timezone identifiers
     */
    public function getTimezonesForRegion(string $region): array
    {
        $this->ensureLoaded();

        $normalized = $this->normalizeRegionName($region);
        if (null === $normalized) {
            return [];
        }

        $list = self::$byRegion[$normalized] ?? [];

        sort($list);

        return $list;
    }

    /**
     * Returns the canonical region name if the given name matches a known region (case-insensitive).
     */
    public function resolveRegion(string $name): ?string
    {
        $this->ensureLoaded();

        return $this->normalizeRegionName($name);
    }

    /**
     * Returns the region (display name) for a timezone identifier that contains a slash.
     * e.g. "Europe/Madrid" → "Europe".
     * Identifiers without a slash or with prefix "Etc" (Others) return null.
     */
    public function getRegionForTimezone(string $timezoneIdentifier): ?string
    {
        $this->ensureLoaded();

        $id = trim($timezoneIdentifier);
        if ('' === $id) {
            return null;
        }

        $pos = strpos($id, '/');
        if (false === $pos) {
            return null;
        }

        $prefix = substr($id, 0, $pos);

        if ('Etc' === $prefix || !isset(self::$byRegion[$prefix])) {
            return null;
        }

        return $prefix;
    }

    private function normalizeRegionName(string $name): ?string
    {
        $trimmed = trim($name);
        if ('' === $trimmed) {
            return null;
        }

        $lower = strtolower($trimmed);
        foreach (self::REGION_ORDER as $region) {
            if (strtolower($region) === $lower) {
                return $region;
            }
        }

        return null;
    }

    private function ensureLoaded(): void
    {
        if (null !== self::$byRegion) {
            return;
        }

        self::$byRegion = [];
        $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL);

        foreach ($identifiers as $id) {
            $pos = strpos($id, '/');
            if (false === $pos) {
                continue;
            }

            $region = substr($id, 0, $pos);
            if (!isset(self::$byRegion[$region])) {
                self::$byRegion[$region] = [];
            }
            self::$byRegion[$region][] = $id;
        }
    }
}
