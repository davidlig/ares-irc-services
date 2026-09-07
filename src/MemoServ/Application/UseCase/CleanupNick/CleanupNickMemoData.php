<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\CleanupNick;

final readonly class CleanupNickMemoData
{
    public function __construct(
        public int $nickId,
    ) {}
}
