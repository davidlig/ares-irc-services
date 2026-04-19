<?php

declare(strict_types=1);

namespace App\Domain\IRC\Network;

enum ChannelMemberRole: string
{
    case None = '';
    case Voice = 'voice';
    case HalfOp = 'halfop';
    case Op = 'op';
    case Admin = 'admin';
    case Owner = 'owner';

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
