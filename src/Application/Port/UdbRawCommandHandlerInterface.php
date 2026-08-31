<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Handles OperServ RAW UDB mutations (DB * INS / DB * DEL) against the
 * authoritative services store when the active protocol is UDB-capable
 * (unrealudb). Implementations validate the exact UDB 4 schema, persist
 * locally and propagate through the record writer.
 */
interface UdbRawCommandHandlerInterface
{
    /**
     * Applies "DB * INS <block>::<encoded-path> <value>".
     *
     * @param string $blockPath Block letter and encoded path (e.g. "S::propagator")
     * @param string $value     Record value (never empty)
     */
    public function ins(string $blockPath, string $value): UdbRawCommandResult;

    /**
     * Applies "DB * DEL <block>::<encoded-path>" (cascades to children).
     *
     * @param string $blockPath Block letter and encoded path (e.g. "N::nick")
     */
    public function del(string $blockPath): UdbRawCommandResult;
}
