<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;

final readonly class UdbDatasetCheckpoint
{
    public function __construct(
        public UdbStreamOrigin $origin,
        public UdbUnsignedDecimal $watermark,
    ) {}
}
