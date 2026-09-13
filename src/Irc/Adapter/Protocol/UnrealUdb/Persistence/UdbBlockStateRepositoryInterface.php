<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlockState;
use DateTimeImmutable;

interface UdbBlockStateRepositoryInterface
{
    /**
     * All block states as a map of block letter => state.
     *
     * @return array<string, UdbBlockState>
     */
    public function all(): array;

    /** Inserts or updates the complete persisted manifest of a block. */
    public function upsert(
        string $block,
        string $checksum,
        int $recordCount,
        ?DateTimeImmutable $modifiedAt = null,
    ): void;
}
