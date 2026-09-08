<?php

declare(strict_types=1);

namespace App\OperServ\Domain\Policy;

use function ctype_alnum;
use function preg_match;
use function str_contains;
use function str_split;
use function strpos;
use function strtolower;
use function substr;
use function trim;

final class GlineMaskPolicy
{
    private const int MIN_HOST_ALNUM_CHARS = 4;

    private function __construct() {}

    public static function isValidInput(string $mask): bool
    {
        return '' !== $mask && !str_contains($mask, '!');
    }

    public static function isNickname(string $mask): bool
    {
        return '' !== $mask && !str_contains($mask, '@') && !str_contains($mask, '!');
    }

    public static function isGlobal(string $mask): bool
    {
        $mask = strtolower(trim($mask));

        return '*' === $mask || '*!*@*' === $mask || '*@*' === $mask || 1 === preg_match('/^\\*!?\\*?@\\*+$/', $mask);
    }

    public static function isSafe(string $mask): bool
    {
        if (str_contains($mask, '!') || !str_contains($mask, '@')) {
            return false;
        }

        $at = strpos($mask, '@');
        if (false === $at) {
            return false;
        }

        $user = substr($mask, 0, $at);
        foreach (str_split($user) as $character) {
            if (ctype_alnum($character)) {
                return true;
            }
        }

        $hostCharacters = 0;
        foreach (str_split(substr($mask, $at + 1)) as $character) {
            if (ctype_alnum($character)) {
                ++$hostCharacters;
            }
        }

        return self::MIN_HOST_ALNUM_CHARS <= $hostCharacters;
    }
}
