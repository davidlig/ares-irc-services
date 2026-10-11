<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;

use function count;

/** Historical name retained as an adapter facade; INF counts logical rows, not prefix nodes. */
final class UdbStructuralNodeCount
{
    /**
     * @param array<string, mixed> $records canonical encoded path => logical row
     */
    public static function fromRecords(UdbBlock $block, array $records): int
    {
        return count($records);
    }
}
