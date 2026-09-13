<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence;

/**
 * Atomic persistence boundary for live authoritative record mutations.
 *
 * A changed record and its complete block manifest are committed together.
 */
interface UdbRecordMutationStoreInterface
{
    /** @return bool true when the authoritative store changed */
    public function upsertWithManifest(string $block, string $path, string $value): bool;

    /** @return bool true when at least one record was removed */
    public function deleteCascadeWithManifest(string $block, string $path): bool;

    /** Atomically compare-and-delete one expired K profile and refresh its manifest. */
    public function expireLineWithManifest(string $path, int $expectedExpires, int $now): bool;
}
