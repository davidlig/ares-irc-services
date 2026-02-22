<?php

declare(strict_types=1);

namespace App\Domain\IRC\Network;

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
    case None   = '';
    case Voice  = '+';   // +v
    case HalfOp = '%';   // +h
    case Op     = '@';   // +o
    case Admin  = '~';   // +a  (SJOIN uses ~, not & like /NAMES)
    case Owner  = '*';   // +q  (SJOIN uses *)

    public static function fromSjoinPrefix(string $prefix): self
    {
        return match($prefix) {
            '+'     => self::Voice,
            '%'     => self::HalfOp,
            '@'     => self::Op,
            '~'     => self::Admin,
            '*'     => self::Owner,
            default => self::None,
        };
    }

    /**
     * Extracts all prefix characters from the start of an SJOIN member entry
     * and returns the highest privilege role.
     * e.g. "+@James" → Op  (@ > +)
     */
    public static function fromSjoinEntry(string &$entry): self
    {
        $prefixChars = ['+', '%', '@', '~', '*'];
        $found = self::None;
        $priority = [self::None, self::Voice, self::HalfOp, self::Op, self::Admin, self::Owner];

        while ('' !== $entry && in_array($entry[0], $prefixChars, true)) {
            $role = self::fromSjoinPrefix($entry[0]);
            if (array_search($role, $priority, true) > array_search($found, $priority, true)) {
                $found = $role;
            }
            $entry = substr($entry, 1);
        }

        return $found;
    }

    public function label(): string
    {
        return match($this) {
            self::None   => 'none',
            self::Voice  => 'voice',
            self::HalfOp => 'halfop',
            self::Op     => 'op',
            self::Admin  => 'admin',
            self::Owner  => 'owner',
        };
    }
}
