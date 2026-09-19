<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Unsuspend;

final readonly class UnsuspendNickResult
{
    public function __construct(
        public UnsuspendNickOutcome $outcome,
        public string $targetNickname,
    ) {}
}
