<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Port for writing records to a protocol-native UDB database.
 *
 * Implementations encapsulate the UDB wire format (source SID prefix, target,
 * trailing value for multi-word values) so domain-driven subscribers never
 * build protocol strings themselves. Only meaningful when the active protocol
 * is UDB-capable (e.g. UnrealUdb); the caller is responsible for protocol gating.
 */
interface UdbRecordWriterInterface
{
    /**
     * Insert or replace a record.
     *
     * @param string $block Block letter (N, C, I, K, S, L)
     * @param string $path  Record path WITHOUT the block prefix (e.g. "davidlig::vhost")
     * @param string $value Record value (may contain spaces)
     */
    public function insert(string $block, string $path, string $value): void;

    /**
     * Delete a record node and cascade to its children.
     *
     * @param string $block Block letter (N, C, I, K, S, L)
     * @param string $path  Record path WITHOUT the block prefix (e.g. "davidlig::vhost")
     */
    public function delete(string $block, string $path): void;

    /**
     * Request a full block synchronization from the given source server.
     *
     * @param string $block     Block letter (N, C, I, K, S, L)
     * @param string $sourceSid SID of the server holding the authoritative snapshot
     */
    public function requestSync(string $block, string $sourceSid): void;

    /**
     * Drop (empty) an entire block.
     *
     * @param string $block Block letter (N, C, I, K, S, L)
     */
    public function dropBlock(string $block): void;
}
