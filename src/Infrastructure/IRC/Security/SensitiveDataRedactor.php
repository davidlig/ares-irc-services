<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Security;

use function count;

/**
 * Masks passwords and credentials in NickServ command strings
 * before they are written to log files.
 *
 * Handled patterns (case-insensitive):
 *   REGISTER  <password> <email>   → REGISTER ****** <email>
 *   IDENTIFY  <nick> <password>    → IDENTIFY <nick> ******
 *   SET PASSWORD <new_password>    → SET PASSWORD ******
 */
final readonly class SensitiveDataRedactor
{
    private const MASK = '******';

    public static function redactNickServCommand(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text), 4);
        $cmd = strtoupper($parts[0] ?? '');

        switch ($cmd) {
            case 'REGISTER':
                // REGISTER <password> <email> — mask position 1 (password)
                if (isset($parts[1])) {
                    $parts[1] = self::MASK;
                }
                break;

            case 'IDENTIFY':
                // IDENTIFY <nick> <password> — mask last position
                $last = count($parts) - 1;
                if ($last >= 1) {
                    $parts[$last] = self::MASK;
                }
                break;

            case 'SET':
                // SET PASSWORD <new_password> — mask position 2
                if (isset($parts[1]) && 'PASSWORD' === strtoupper($parts[1]) && isset($parts[2])) {
                    $parts[2] = self::MASK;
                }
                break;
        }

        return implode(' ', $parts);
    }
}
