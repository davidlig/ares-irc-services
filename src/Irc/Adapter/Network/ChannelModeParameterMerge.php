<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use function is_numeric;
use function strcmp;

/** Stateless ordering choices supplied by the native protocol adapter. */
enum ChannelModeParameterMerge
{
    case LexicalMinimum;
    case LexicalMaximum;
    case NumericMaximum;

    public function prefersIncoming(string $current, string $incoming): bool
    {
        return match ($this) {
            self::LexicalMinimum => strcmp($incoming, $current) < 0,
            self::LexicalMaximum => strcmp($incoming, $current) > 0,
            self::NumericMaximum => is_numeric($incoming) && is_numeric($current) && (int) $incoming > (int) $current,
        };
    }
}
