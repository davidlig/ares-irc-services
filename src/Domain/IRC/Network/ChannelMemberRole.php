<?php

declare(strict_types=1);

namespace App\Domain\IRC\Network;

use function in_array;

/**
 * Channel privilege levels as transmitted in SJOIN buffers.
 *
 * IMPORTANT: The SJOIN prefix characters differ from the IRC PREFIX convention:
 *   SJOIN ~ = Channel Admin (+a), but in IRC /NAMES ~ = Owner (+q)
 *   SJOIN * = Channel Owner (+q), but in IRC /NAMES * = Owner (+q)
 *
 * See: https://www.unrealircd.org/docs/Server_protocol:SJOIN_command
 */
enum ChannelMemberRole: string
{
    case None = '';
    case Voice = '+';
    case HalfOp = '%';
    case Op = '@';
    case Admin = '~';
    case Owner = '*';

    public static function fromSjoinPrefix(string $prefix): self
    {
        return match ($prefix) {
            '+' => self::Voice,
            '%' => self::HalfOp,
            '@' => self::Op,
            '~' => self::Admin,
            '*' => self::Owner,
            default => self::None,
        };
    }

    /**
     * Maps MODE command letter (e.g. +o, +v) to role. Used when parsing MODE #channel ±modes.
     * UnrealIRCd PREFIX=(qaohv): only lowercase v,h,o,a,q are prefix modes. Uppercase letters
     * (e.g. V=noinvite, D=delayjoin, R=regonly) are channel setting modes and must not match.
     */
    public static function fromModeLetter(string $letter): ?self
    {
        return match ($letter) {
            'v' => self::Voice,
            'h' => self::HalfOp,
            'o' => self::Op,
            'a' => self::Admin,
            'q' => self::Owner,
            default => null,
        };
    }

    /**
     * MODE letter (q, a, o, h, v) for this role. Used when building MODE strings.
     */
    public function toModeLetter(): string
    {
        return match ($this) {
            self::Voice => 'v',
            self::HalfOp => 'h',
            self::Op => 'o',
            self::Admin => 'a',
            self::Owner => 'q',
            self::None => '',
        };
    }

    /**
     * Extracts all prefix characters from the start of an SJOIN member entry
     * and returns the highest privilege role.
     * e.g. "+@James" → Op  (@ > +).
     */
    public static function fromSjoinEntry(string &$entry): self
    {
        $letters = self::fromSjoinEntryToLetters($entry);

        return self::highestRoleFromLetters($letters);
    }

    /**
     * Extracts all prefix characters from the start of an SJOIN member entry
     * and returns the list of mode letters (q, a, o, h, v) the user has.
     * Consumes the prefix chars from $entry (same as fromSjoinEntry).
     *
     * @return list<string>
     */
    public static function fromSjoinEntryToLetters(string &$entry): array
    {
        $prefixChars = ['+', '%', '@', '~', '*'];
        $letters = [];

        while ('' !== $entry && in_array($entry[0], $prefixChars, true)) {
            $role = self::fromSjoinPrefix($entry[0]);
            $entry = substr($entry, 1);
            if (self::None !== $role) {
                $letter = $role->toModeLetter();
                if ('' !== $letter && !in_array($letter, $letters, true)) {
                    $letters[] = $letter;
                }
            }
        }

        return $letters;
    }

    /**
     * Highest role from a list of mode letters (q > a > o > h > v).
     *
     * @param list<string> $letters
     */
    public static function highestRoleFromLetters(array $letters): self
    {
        $order = ['q' => self::Owner, 'a' => self::Admin, 'o' => self::Op, 'h' => self::HalfOp, 'v' => self::Voice];
        $found = self::None;

        foreach ($letters as $letter) {
            $role = $order[$letter] ?? null;
            if (null !== $role) {
                $priority = [self::None, self::Voice, self::HalfOp, self::Op, self::Admin, self::Owner];
                if (array_search($role, $priority, true) > array_search($found, $priority, true)) {
                    $found = $role;
                }
            }
        }

        return $found;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'none',
            self::Voice => 'voice',
            self::HalfOp => 'halfop',
            self::Op => 'op',
            self::Admin => 'admin',
            self::Owner => 'owner',
        };
    }
}
