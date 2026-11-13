<?php

declare(strict_types=1);

namespace App\Irc\Domain\ValueObject;

use function strtolower;
use function strtr;

/** Byte-oriented IRC identity folding; never applies locale or Unicode casing. */
enum CaseMapping: string
{
    case Ascii = 'ascii';
    case Rfc1459 = 'rfc1459';
    case StrictRfc1459 = 'strict-rfc1459';

    public function fold(string $value): string
    {
        $value = strtolower($value);

        return match ($this) {
            self::Ascii => $value,
            self::Rfc1459 => strtr($value, '[]\\^', '{}|~'),
            self::StrictRfc1459 => strtr($value, '[]\\', '{}|'),
        };
    }
}
