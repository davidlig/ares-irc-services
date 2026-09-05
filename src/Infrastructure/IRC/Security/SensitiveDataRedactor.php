<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Security;

use function count;
use function in_array;

/**
 * Masks passwords and credentials in NickServ command strings
 * before they are written to log files.
 *
 * Handled patterns (case-insensitive):
 *   REGISTER  <password> <email>   → REGISTER ****** ******
 *   IDENTIFY  <nick> <password>    → IDENTIFY <nick> ******
 *   SET PASSWORD <new_password>    → SET PASSWORD ******
 *   VERIFY <token>                  → VERIFY ******
 *   RECOVER <nick> <token>         → RECOVER <nick> ******
 */
final readonly class SensitiveDataRedactor
{
    private const string MASK = '******';

    public static function redactNickServCommand(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text), 4);
        $cmd = strtoupper($parts[0] ?? '');

        switch ($cmd) {
            case 'REGISTER':
                if (isset($parts[1])) {
                    $parts[1] = self::MASK;
                }
                if (isset($parts[2])) {
                    $parts[2] = self::MASK;
                }
                break;

            case 'IDENTIFY':
                $last = count($parts) - 1;
                if ($last >= 1) {
                    $parts[$last] = self::MASK;
                }
                break;

            case 'VERIFY':
                if (isset($parts[1])) {
                    $parts[1] = self::MASK;
                }
                break;

            case 'RECOVER':
                if (isset($parts[2])) {
                    $parts[2] = self::MASK;
                }
                break;

            case 'SET':
                if (isset($parts[1]) && in_array(strtoupper($parts[1]), ['EMAIL', 'PASSWORD'], true)) {
                    if (isset($parts[2])) {
                        $parts[2] = self::MASK;
                    }
                    if (isset($parts[3])) {
                        $parts[3] = self::MASK;
                    }
                }
                break;

            case 'SASET':
                if (isset($parts[2]) && in_array(strtoupper($parts[2]), ['EMAIL', 'PASSWORD'], true) && isset($parts[3])) {
                    $parts[3] = self::MASK;
                }
                break;
        }

        return implode(' ', $parts);
    }
}
