<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

use App\Irc\Domain\ValueObject\CaseMapping;

use function inet_pton;
use function intdiv;
use function ord;
use function preg_match;
use function preg_quote;
use function str_replace;
use function strlen;
use function substr;

/** Stateless primitives only: callers own protocol-specific mask and CIDR rules. */
final class IrcMaskPattern
{
    public static function matches(string $pattern, string $subject, string $caseMapping = 'ascii'): bool
    {
        $mapping = CaseMapping::tryFrom($caseMapping) ?? CaseMapping::Ascii;
        $expression = str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($mapping->fold($pattern), '~'));

        return 1 === preg_match('~\\A' . $expression . '\\z~sD', $mapping->fold($subject));
    }

    /** Compare a validated prefix, without selecting a protocol's allowed range. */
    public static function addressMatches(string $network, string $address, int $prefix): bool
    {
        $networkBytes = inet_pton($network);
        $addressBytes = inet_pton($address);
        if (false === $networkBytes || false === $addressBytes || strlen($networkBytes) !== strlen($addressBytes)) {
            return false;
        }
        $wholeBytes = intdiv($prefix, 8);
        if (substr($networkBytes, 0, $wholeBytes) !== substr($addressBytes, 0, $wholeBytes)) {
            return false;
        }
        $remaining = $prefix % 8;

        return 0 === $remaining || (ord($networkBytes[$wholeBytes]) & (255 << (8 - $remaining))) === (ord($addressBytes[$wholeBytes]) & (255 << (8 - $remaining)));
    }
}
