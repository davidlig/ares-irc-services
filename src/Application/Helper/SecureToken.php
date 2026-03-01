<?php

declare(strict_types=1);

namespace App\Application\Helper;

/**
 * Generates cryptographically secure random tokens (hex string).
 * Use for verification tokens, recovery tokens, etc.
 */
final class SecureToken
{
    /**
     * Returns a random hex string of exactly $length characters.
     *
     * @param int $length Desired string length in hex characters (default 32 = 16 bytes entropy)
     */
    public static function hex(int $length = 32): string
    {
        $bytes = max(1, (int) ceil($length / 2));

        return substr(bin2hex(random_bytes($bytes)), 0, $length);
    }
}
