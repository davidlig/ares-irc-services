<?php

declare(strict_types=1);

namespace App\Shared\Domain;

use function strtolower;
use function strtr;

/**
 * Fixed RFC 1459 identity fold for nicknames and channel names.
 *
 * Byte-oriented: lowers ASCII letters and maps `[]\^` to `{}|~`. It never applies locale or
 * Unicode casing, so stored lookup keys stay stable across PHP builds and database engines.
 */
final class IrcIdentityFold
{
    public static function fold(string $value): string
    {
        return strtr(strtolower($value), '[]\\^', '{}|~');
    }

    public static function equals(string $left, string $right): bool
    {
        return self::fold($left) === self::fold($right);
    }
}
