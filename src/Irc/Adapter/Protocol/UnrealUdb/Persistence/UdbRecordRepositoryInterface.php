<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Persistence;

interface UdbRecordRepositoryInterface
{
    /**
     * All records of one block as a map of canonical encoded path => value.
     *
     * @return array<string, string>
     */
    public function recordsByBlock(string $block): array;

    /** Inserts the record or updates the value of the existing (block, identity) row. */
    public function upsert(string $block, string $path, string $value): void;

    /**
     * Deletes the record at the path and all of its descendants
     * (case-insensitive, mirroring the UDB tree cascade).
     */
    public function deleteCascade(string $block, string $path): void;

    /**
     * Seeds one block from a path => value map inside a single transaction,
     * merging (upsert) with any records already present. Used once per block
     * by the store initializer so concurrent mutations are never lost.
     *
     * @param array<string, string> $records Encoded path => value
     */
    public function seedBlock(string $block, array $records): void;

    /**
     * Replaces the ENTIRE block with the given records in one transaction
     * (existing rows are deleted first). Used by the offline and wire
     * takeovers so the imported generation is never merged with stale rows.
     *
     * @param array<string, string> $records Encoded path => value
     */
    public function replaceBlock(string $block, array $records): void;
}
