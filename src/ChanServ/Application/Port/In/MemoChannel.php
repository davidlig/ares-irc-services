<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

final readonly class MemoChannel
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
