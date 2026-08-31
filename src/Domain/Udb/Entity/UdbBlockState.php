<?php

declare(strict_types=1);

namespace App\Domain\Udb\Entity;

use DateTimeImmutable;

/**
 * Per-block initialization marker of the authoritative UDB store.
 *
 * The mere existence of one row per UDB block (N, C, I, S, L, K) marks that
 * block as initialized: N/C/K were seeded from services SQL and I/S/L start
 * empty. Checksum/syncedAt are informational.
 */
class UdbBlockState
{
    private int $id;

    private DateTimeImmutable $syncedAt;

    public function __construct(
        private readonly string $block,
        private string $checksum,
        ?DateTimeImmutable $syncedAt = null,
    ) {
        $this->syncedAt = $syncedAt ?? new DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getBlock(): string
    {
        return $this->block;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getSyncedAt(): DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function update(string $checksum): void
    {
        $this->checksum = $checksum;
        $this->syncedAt = new DateTimeImmutable();
    }
}
