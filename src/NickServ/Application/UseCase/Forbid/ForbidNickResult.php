<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Forbid;

final readonly class ForbidNickResult
{
    public function __construct(
        public ForbidNickOutcome $outcome,
        public string $targetNickname,
        public string $reason,
    ) {}
}
