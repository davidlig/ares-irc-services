<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

/**
 * Port for writing record mutations to the protocol-native UDB database.
 *
 * Implementations validate the full UDB 4 schema, persist the mutation to
 * the authoritative services store FIRST and only then coordinate with the
 * UDB session state machine: mutations are only transmitted while services
 * are the confirmed UDB authority; otherwise they are queued in order and
 * delivered on the next ready flush (or recovered through snapshot
 * divergence).
 *
 * Only meaningful when the active protocol is UDB-capable (unrealudb); the
 * caller is responsible for protocol gating.
 */
interface UdbRecordWriterInterface
{
    /**
     * Insert or replace a record.
     *
     * @param string $block Block letter (N, C, I, S, L, K)
     * @param string $path  Record path WITHOUT the block prefix, raw components
     *                      joined with "::" (e.g. "davidlig::vhost")
     * @param string $value Record value (may contain spaces)
     *
     * @return bool true when the mutation was validated, persisted and handed
     *              to the wire (or queued); false when it was rejected
     */
    public function insert(string $block, string $path, string $value): bool;

    /**
     * Delete a record node (UDB cascades to its children).
     *
     * @param string $block Block letter (N, C, I, S, L, K)
     * @param string $path  Record path WITHOUT the block prefix, raw components
     *                      joined with "::" (e.g. "davidlig::vhost")
     *
     * @return bool true when the deletion was persisted and handed to the
     *              wire (or queued); false when it was rejected
     */
    public function delete(string $block, string $path): bool;
}
