<?php

declare(strict_types=1);

namespace App\Irc\Domain\ValueObject;

use App\Shared\Domain\IrcIdentityFold;

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
        return match ($this) {
            self::Ascii => strtolower($value),
            self::Rfc1459 => IrcIdentityFold::fold($value),
            self::StrictRfc1459 => strtr(strtolower($value), '[]\\', '{}|'),
        };
    }
}
