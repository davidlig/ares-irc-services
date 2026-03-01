<?php

declare(strict_types=1);

namespace App\Application\Helper;

/**
 * Masks an email address for display (e.g. "token sent to da****@domain.com")
 * without revealing the full address.
 */
final class EmailMasker
{
    private const string MASK = '****';

    private const string FALLBACK = '***@***';

    /**
     * Returns a masked hint: first 2 chars of local part + **** + @ + full domain.
     *
     * Example: "david@example.com" -> "da****@example.com"
     *
     * Single-char local part: "d@x.com" -> "d****@x.com"
     */
    public static function mask(string $email): string
    {
        $email = trim($email);
        if ('' === $email) {
            return self::FALLBACK;
        }

        $at = strpos($email, '@');
        if (false === $at) {
            return self::FALLBACK;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        $visible = mb_substr($local, 0, 2);
        if ('' === $visible) {
            return self::FALLBACK;
        }

        return $visible . self::MASK . $domain;
    }
}
