<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Security;

use App\Irc\Adapter\Protocol\IRCMessage;

use function count;
use function implode;
use function in_array;
use function preg_match;
use function preg_split;
use function strtoupper;
use function trim;

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

    public static function redactIrcMessage(IRCMessage $message): IRCMessage
    {
        $command = strtoupper($message->command);
        $params = $message->params;
        $trailing = $message->trailing;

        if ('PASS' === $command) {
            if (isset($params[0])) {
                $params[0] = self::MASK;
            }
            if (null !== $trailing) {
                $trailing = self::MASK;
            }
        } elseif ('SERVER' === $command && isset($params[2])) {
            // InspIRCd SERVER is <name> <password> <sid>; Unreal SERVER has
            // no password and uses <name> <hop-count> instead.
            $params[1] = self::MASK;
        } elseif ('ERROR' === $command && null !== $trailing) {
            $trailing = self::redactIrcLine($trailing);
        }

        if ($params === $message->params && $trailing === $message->trailing) {
            return $message;
        }

        return new IRCMessage(
            command: $message->command,
            prefix: $message->prefix,
            params: $params,
            trailing: $trailing,
            direction: $message->direction,
        );
    }

    public static function redactIrcLine(string $line): string
    {
        $message = IRCMessage::fromRawLine($line);
        $redacted = self::redactIrcMessage($message);

        return $redacted === $message ? $line : $redacted->toRawLine();
    }

    public static function redactNickServCommand(string $text): string
    {
        $parts = preg_split('/\s+/', trim($text), 4) ?: [$text];

        $cmd = strtoupper($parts[0]);

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
                if (isset($parts[1], $parts[2], $parts[3]) && 1 === preg_match('/^[#&+!]/', $parts[1]) && 'FOUNDER' === strtoupper($parts[2])) {
                    $targetAndToken = preg_split('/\\s+/', $parts[3], 2) ?: [$parts[3]];
                    if (isset($targetAndToken[1])) {
                        $parts[3] = $targetAndToken[0] . ' ' . self::MASK;
                    }
                    break;
                }
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
