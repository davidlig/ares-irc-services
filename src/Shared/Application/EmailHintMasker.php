<?php

declare(strict_types=1);

namespace App\Shared\Application;

use function mb_substr;
use function preg_match;
use function trim;

/** Provides the same privacy-preserving email hint for every service. */
final class EmailHintMasker
{
    private const string FALLBACK = '***@***';

    public static function mask(string $email): string
    {
        if (1 !== preg_match('/\A([^@\s]+)@([^@\s.]+(?:\.[^@\s.]+)+)\z/u', trim($email), $matches)) {
            return self::FALLBACK;
        }

        return mb_substr($matches[1], 0, 2) . '****@' . mb_substr($matches[2], 0, 1) . '****.***';
    }
}
