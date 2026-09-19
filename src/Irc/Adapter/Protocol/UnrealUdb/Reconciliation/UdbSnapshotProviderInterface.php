<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;

/**
 * Provides the authoritative services snapshot for one UDB block.
 *
 * N, C and K are rebuilt from services SQL; I, S and L come from the
 * persistent IRCd mirror captured during bootstrap. Returned paths are
 * canonical percent-encoded WITHOUT the block prefix.
 */
interface UdbSnapshotProviderInterface
{
    /** @return array<string, string> Encoded path => value */
    public function recordsForBlock(UdbBlock $block): array;

    /** Deterministic UDB checksum of the block's current snapshot. */
    public function checksumForBlock(UdbBlock $block): string;
}
