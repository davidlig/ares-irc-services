<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Wire;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;

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
        public UdbFrameKind $kind,
        public string $sourceSid,
        public string $target,
        public ?int $roundId = null,
        public ?UdbBlock $block = null,
        public ?string $txid = null,
        public ?string $checksum = null,
        public ?int $timestamp = null,
        public ?string $path = null,
        public ?string $value = null,
        public ?string $propagator = null,
        public ?string $epoch = null,
        /** @var list<string> */
        public array $capabilities = [],
        public ?string $subcommand = null,
        public ?int $errorCode = null,
        public ?string $status = null,
        public ?int $count = null,
    ) {}
}
