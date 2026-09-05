<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;

/**
 * Parsed OperServ RAW UDB mutation path.
 *
 * @internal
 */
final readonly class ParsedUdbPath
{
    /**
     * @param list<string> $components Decoded path components without the block letter
     */
    public function __construct(
        public UdbBlock $block,
        public string $blockPath,
        public array $components,
    ) {}
}
