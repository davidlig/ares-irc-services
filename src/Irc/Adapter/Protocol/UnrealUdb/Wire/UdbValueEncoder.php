<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use InvalidArgumentException;

use function base64_encode;
use function strlen;

final class UdbValueEncoder
{
    public static function encode(UdbValue $value): string
    {
        if (UdbValueKind::String === $value->kind && strlen($value->value) > UdbPathCodec::VALUE_MAX) {
            throw new InvalidArgumentException('A UDB string value exceeds the canonical limit.');
        }

        return match ($value->kind) {
            UdbValueKind::None => '!n',
            UdbValueKind::Number => '!u' . $value->value,
            UdbValueKind::String => '!s' . base64_encode($value->value),
        };
    }
}
