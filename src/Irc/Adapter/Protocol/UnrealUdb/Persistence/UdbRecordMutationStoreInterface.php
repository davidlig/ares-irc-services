<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;

/**
 * Atomic persistence boundary for live authoritative record mutations.
 *
 * A changed record and its complete block manifest are committed together.
 */
interface UdbRecordMutationStoreInterface
{
    /** Start a fresh process epoch without clearing an imported checkpoint. */
    public function beginLocalStream(UdbStreamOrigin $origin): UdbDatasetCheckpoint;

    /** Null means unchanged; otherwise returns the sequence committed atomically with the row and manifest. */
    public function upsertWithManifest(string $block, string $path, UdbValue $value, UdbStreamOrigin $origin): ?UdbUnsignedDecimal;

    /** Null means unchanged; otherwise returns the sequence committed atomically with the deletion and manifest. */
    public function deleteCascadeWithManifest(string $block, string $path, UdbStreamOrigin $origin): ?UdbUnsignedDecimal;

    /** Atomically compare-and-delete one expired K profile and refresh its manifest. */
    public function expireLineWithManifest(string $path, int $expectedExpires, int $now, UdbStreamOrigin $origin): ?UdbUnsignedDecimal;

    /** Allocates one common-stream sequence for an explicit DRP operation without deleting SQL-owned data. */
    public function allocateBlockDropSequence(string $block, UdbStreamOrigin $origin): UdbUnsignedDecimal;
}
