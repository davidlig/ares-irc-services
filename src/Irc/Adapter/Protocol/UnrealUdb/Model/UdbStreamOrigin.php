<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Model;

use InvalidArgumentException;

use function preg_match;

final readonly class UdbStreamOrigin
{
    public function __construct(
        public string $sid,
        public string $epoch,
    ) {
        if (1 !== preg_match('/^[0-9A-Z]{3}$/D', $sid) || 1 !== preg_match('/^[0-9a-f]{16}$/D', $epoch)) {
            throw new InvalidArgumentException('A UDB stream origin requires a canonical SID and epoch.');
        }
    }
}
