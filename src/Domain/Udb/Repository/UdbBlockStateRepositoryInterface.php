<?php

declare(strict_types=1);

namespace App\Domain\Udb\Repository;

use App\Domain\Udb\Entity\UdbBlockState;

interface UdbBlockStateRepositoryInterface
{
    /**
     * All block states as a map of block letter => state.
     *
     * @return array<string, UdbBlockState>
     */
    public function all(): array;

    /** Inserts the state row or updates the checksum of the existing row. */
    public function upsert(string $block, string $checksum): void;
}
