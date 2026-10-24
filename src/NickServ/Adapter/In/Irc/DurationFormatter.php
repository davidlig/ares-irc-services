<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc;

use InvalidArgumentException;

use function array_slice;
use function count;

final class DurationFormatter
{
    /**
     * @var list<array{seconds: int, key: string}>
     */
    private const UNITS = [
        ['seconds' => 31_536_000, 'key' => 'year'],
        ['seconds' => 2_592_000, 'key' => 'month'],
        ['seconds' => 86_400, 'key' => 'day'],
        ['seconds' => 3_600, 'key' => 'hour'],
        ['seconds' => 60, 'key' => 'minute'],
        ['seconds' => 1, 'key' => 'second'],
    ];

    /**
     * @param callable(string, array<string, string>): string $translate
     */
    public static function format(int $seconds, callable $translate): string
    {
        if (0 > $seconds) {
            throw new InvalidArgumentException('Duration must not be negative.');
        }

        $remainingSeconds = $seconds;
        /** @var list<string> $parts */
        $parts = [];

        foreach (self::UNITS as $unit) {
            $amount = intdiv($remainingSeconds, $unit['seconds']);
            if (0 === $amount) {
                continue;
            }

            $form = 1 === $amount ? 'singular' : 'plural';
            $label = $translate('duration.unit.' . $unit['key'] . '.' . $form, []);
            $parts[] = $amount . ' ' . $label;
            $remainingSeconds %= $unit['seconds'];
        }

        if ([] === $parts) {
            $parts[] = '0 ' . $translate('duration.unit.second.plural', []);
        }

        if (1 === count($parts)) {
            return $parts[0];
        }

        $lastPart = $parts[count($parts) - 1];
        $prefix = implode($translate('duration.list_separator', []), array_slice($parts, 0, -1));

        return $translate('duration.list_join', ['prefix' => $prefix, 'last' => $lastPart]);
    }
}
