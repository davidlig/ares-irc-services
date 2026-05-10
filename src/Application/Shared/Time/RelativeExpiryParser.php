<?php

declare(strict_types=1);

namespace App\Application\Shared\Time;

use DateInterval;
use DateTimeImmutable;

use function preg_match;
use function strtolower;
use function trim;

final readonly class RelativeExpiryParser
{
    private const string RELATIVE_PATTERN = '/^(\d+)([dhm])$/';

    public static function parse(string $value, ?DateTimeImmutable $now = null): ?DateTimeImmutable
    {
        $value = self::normalize($value);

        if (self::isPermanent($value)) {
            return null;
        }

        $matches = [];
        if (1 !== preg_match(self::RELATIVE_PATTERN, $value, $matches)) {
            return null;
        }

        $date = $now ?? new DateTimeImmutable();

        return $date->add(new DateInterval(self::intervalSpec((int) $matches[1], $matches[2])));
    }

    public static function isPermanent(string $value): bool
    {
        return '0' === self::normalize($value);
    }

    private static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    private static function intervalSpec(int $value, string $unit): string
    {
        return match ($unit) {
            'd' => "P{$value}D",
            'h' => "PT{$value}H",
            'm' => "PT{$value}M",
        };
    }
}
