<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Takeover;

use RuntimeException;

/** Rejects legacy text snapshots; native UDB 4 imports use the authorized wire flow. */
final readonly class UdbOfflineTakeover implements UdbOfflineTakeoverInterface
{
    public function takeover(string $directory, bool $apply = true): string
    {
        throw new RuntimeException('Offline text snapshots are unsupported; use the authorized native UDB wire takeover.');
    }
}
