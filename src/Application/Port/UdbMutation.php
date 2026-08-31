<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * A single UDB record mutation prepared for the wire.
 *
 * The path is already canonical percent-encoded WITHOUT the block prefix
 * (e.g. "nickserv" for S, "#chan::founder" for C). A null value means delete.
 */
final readonly class UdbMutation
{
    public function __construct(
        public readonly string $block,
        public readonly string $encodedPath,
        public readonly ?string $value,
    ) {}
}
