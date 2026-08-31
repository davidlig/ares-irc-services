<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\Udb\Repository\UdbRecordRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbChecksum;

/**
 * Builds UDB block snapshots and checksums from the authoritative services
 * store. The store is the sole source of truth: SQL was seeded into it once
 * and every subsequent mutation (SQL event or OperServ RAW) went through it.
 */
final readonly class UdbSnapshotProvider implements UdbSnapshotProviderInterface
{
    public function __construct(
        private UdbRecordRepositoryInterface $records,
    ) {}

    /** @return array<string, string> Encoded path => value */
    public function recordsForBlock(UdbBlock $block): array
    {
        $records = $this->records->recordsByBlock($block->letter());

        // UDB has no empty-value records (every validator rejects them), so
        // serving one would be rejected by the peer and abort its staged
        // session. Filter them here so snapshots and checksums stay in sync.
        return array_filter($records, static fn (string $value): bool => '' !== $value);
    }

    public function checksumForBlock(UdbBlock $block): string
    {
        $records = $this->recordsForBlock($block);
        $tuples = [];
        foreach ($records as $path => $value) {
            $tuples[] = [$path, $value];
        }

        return UdbChecksum::fromRecords($tuples);
    }
}
