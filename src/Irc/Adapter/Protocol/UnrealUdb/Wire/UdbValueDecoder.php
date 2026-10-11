<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;

use function base64_decode;
use function base64_encode;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;

final class UdbValueDecoder
{
    public static function decode(string $wire): ?UdbValue
    {
        if ('!n' === $wire) {
            return UdbValue::none();
        }

        if (str_starts_with($wire, '!u')) {
            $decimal = substr($wire, 2);
            $number = UdbUnsignedDecimal::parse($decimal);
            if (null === $number || (string) $number !== $decimal) {
                return null;
            }

            return UdbValue::number($number);
        }

        if (!str_starts_with($wire, '!s')) {
            return null;
        }

        $encoded = substr($wire, 2);
        if ('' !== $encoded && (0 !== strlen($encoded) % 4 || 1 !== preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $encoded))) {
            return null;
        }
        $decoded = base64_decode($encoded, true);
        if (false === $decoded || strlen($decoded) > UdbPathCodec::VALUE_MAX || base64_encode($decoded) !== $encoded) {
            return null;
        }

        return UdbValue::string($decoded);
    }
}
