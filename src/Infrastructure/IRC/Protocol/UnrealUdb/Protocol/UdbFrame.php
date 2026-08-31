<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol;

/**
 * Kind of a parsed UDB DB wire frame.
 */

/**
 * A validated, typed UDB DB frame parsed from the wire (or prepared for it).
 *
 * All fields except kind/sourceSid/target are optional because each frame
 * kind uses a different subset. Paths are stored percent-encoded (as on the
 * wire) without the block prefix; INS/DEL paths include the block prefix
 * exactly as the current UDB mutation grammar defines them.
 */
final readonly class UdbFrame
{
    public function __construct(
        public readonly UdbFrameKind $kind,
        public readonly string $sourceSid,
        public readonly string $target,
        public readonly ?int $roundId = null,
        public readonly ?UdbBlock $block = null,
        public readonly ?string $txid = null,
        public readonly ?string $checksum = null,
        public readonly ?int $timestamp = null,
        public readonly ?string $path = null,
        public readonly ?string $value = null,
        public readonly ?string $propagator = null,
        public readonly ?string $subcommand = null,
        public readonly ?int $errorCode = null,
    ) {}
}
