<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Synchronization;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;

/**
 * A single UDB record mutation prepared for the wire.
 *
 * The path is already canonical percent-encoded WITHOUT the block prefix
 * (e.g. "nickserv" for S, "#chan::founder" for C). A null value means delete.
 */
final readonly class UdbMutation
{
    public function __construct(
        public string $block,
        public string $encodedPath,
        public ?UdbValue $value,
        public ?UdbUnsignedDecimal $sequence = null,
        public ?UdbStreamOrigin $origin = null,
        public bool $blockDrop = false,
    ) {}

    public static function dropBlock(UdbBlock $block): self
    {
        return new self($block->letter(), '', null, blockDrop: true);
    }

    public function assigned(UdbUnsignedDecimal $sequence, UdbStreamOrigin $origin): self
    {
        return new self($this->block, $this->encodedPath, $this->value, $sequence, $origin, $this->blockDrop);
    }
}
