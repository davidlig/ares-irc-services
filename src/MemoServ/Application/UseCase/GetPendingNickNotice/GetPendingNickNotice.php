<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\GetPendingNickNotice;

final readonly class GetPendingNickNotice
{
    public function __construct(
        public int $nickId,
        public string $uid,
    ) {}
}
