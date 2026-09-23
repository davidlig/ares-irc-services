<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbRecord;

use function count;
use function explode;

/** Counts the non-root tree nodes UDB 4 advertises, not the persisted logical records. */
final class UdbStructuralNodeCount
{
    /** @param array<string, string> $records Canonical encoded paths without the block prefix. */
    public static function fromRecords(UdbBlock $block, array $records): int
    {
        $prefixes = [];
        foreach ($records as $path => $value) {
            $prefix = '';
            foreach (explode('::', UdbRecord::identity($path, $block->letter())) as $component) {
                $prefix = '' === $prefix ? $component : $prefix . '::' . $component;
                $prefixes[$prefix] = true;
            }
        }

        return count($prefixes);
    }
}
